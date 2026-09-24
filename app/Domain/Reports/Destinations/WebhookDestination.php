<?php

declare(strict_types=1);

namespace App\Domain\Reports\Destinations;

use App\Domain\Reports\Contracts\ReportDestinationInterface;
use App\Domain\Reports\Contracts\ReportInterface;
use App\Domain\Reports\DataObjects\ReportArtifact;
use App\Domain\Reports\DataObjects\ReportDeliveryOutcome;
use App\Domain\Reports\Models\SavedReport;
use App\Domain\Webhooks\Services\WebhookSigner;
use App\Support\Tenancy\TenantContext;
use App\Support\Validation\PublicHttpsUrl;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

/**
 * A report posted to a URL the customer controls.
 *
 * The destination that makes a scheduled report useful to a system rather than
 * to a person: a warehouse, a finance tool, a spreadsheet somebody keeps in
 * sync. It is genuinely live — no partner agreement, no credential we do not
 * have — which is the reason it is the second destination and not the fourth.
 *
 * Three things it inherits rather than reinvents, because a second
 * implementation of any of them would be a second thing to get wrong:
 *
 *  - **The signature.** The same HMAC scheme as every other outbound webhook,
 *    with the timestamp inside the signed string, so a receiver already
 *    verifying our events verifies this with the code it already has.
 *  - **The URL rule.** A URL supplied by a user and fetched by our server is
 *    the shape of a server-side request forgery, so the same public-HTTPS
 *    check applies.
 *  - **The refusal to throw.** A receiver that is down is an ordinary outcome,
 *    recorded on the run, not an exception that abandons the other
 *    destinations in the same run.
 *
 * The payload carries the report as data rather than as an attachment. A
 * receiver that wanted a file would rather have the rows.
 */
class WebhookDestination implements ReportDestinationInterface
{
    private const TIMEOUT_SECONDS = 15;

    public function __construct(
        private readonly WebhookSigner $signer,
        private readonly TenantContext $tenancy,
    ) {}

    public function key(): string
    {
        return 'webhook';
    }

    public function displayName(): string
    {
        return 'Webhook';
    }

    public function describe(): string
    {
        return 'Posts the report to an HTTPS URL, signed so the receiver can prove it came from us. '
            .'Needs a URL, and a shared secret if the receiver verifies signatures.';
    }

    public function problemsWith(array $config): array
    {
        $url = $config['url'] ?? null;

        if (! is_string($url) || $url === '') {
            return ['A URL is needed.'];
        }

        $validator = Validator::make(
            ['url' => $url],
            ['url' => [new PublicHttpsUrl((bool) config('pms.webhooks.allow_local_endpoints', false))]],
        );

        $problems = $validator->errors()->get('url');

        $secret = $config['secret'] ?? null;

        if ($secret !== null && (! is_string($secret) || strlen($secret) < 16)) {
            // Short enough to brute-force is the same as unsigned, and worse,
            // because the receiver believes it is verifying something.
            $problems[] = 'A signing secret must be at least 16 characters.';
        }

        return array_values($problems);
    }

    public function deliver(
        SavedReport $saved,
        ReportInterface $report,
        ReportArtifact $artifact,
        array $config,
    ): ReportDeliveryOutcome {
        $problems = $this->problemsWith($config);

        if ($problems !== []) {
            return ReportDeliveryOutcome::failed(
                $this->key(),
                (string) ($config['url'] ?? 'no url'),
                implode(' ', $problems),
            );
        }

        $url = (string) $config['url'];
        $body = (string) json_encode($this->payload($saved, $report, $artifact), JSON_UNESCAPED_SLASHES);

        $headers = [
            'Content-Type' => 'application/json',
            'User-Agent' => 'Habitat-Reports/1',
            'X-Habitat-Report' => $report->key(),
            'X-Habitat-Saved-Report' => (string) $saved->getKey(),
        ];

        $secret = $config['secret'] ?? null;

        if (is_string($secret) && $secret !== '') {
            $headers[WebhookSigner::HEADER] = $this->signer->sign($body, $secret);
        }

        try {
            $response = Http::withHeaders($headers)
                ->timeout(self::TIMEOUT_SECONDS)
                ->withBody($body, 'application/json')
                ->post($url);
        } catch (ConnectionException $exception) {
            return ReportDeliveryOutcome::failed(
                $this->key(),
                $url,
                sprintf('The receiver could not be reached: %s', $exception->getMessage()),
            );
        }

        if ($response->failed()) {
            return ReportDeliveryOutcome::failed(
                $this->key(),
                $url,
                sprintf('The receiver answered %d.', $response->status()),
                ['status' => $response->status()],
            );
        }

        // Unsigned is not a failure — a receiver on a private allowlist may not
        // want a secret — but it is worth saying, because a receiver that
        // believes it is verifying signatures and is not has a problem it
        // cannot see.
        $data = ['status' => $response->status(), 'signed' => isset($headers[WebhookSigner::HEADER])];

        return ReportDeliveryOutcome::delivered($this->key(), $url, $data);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(SavedReport $saved, ReportInterface $report, ReportArtifact $artifact): array
    {
        $parameters = $saved->parameters();

        return [
            'saved_report' => [
                'id' => $saved->getKey(),
                'name' => $saved->name,
            ],
            'organization_id' => $this->tenancy->id(),
            'report' => [
                'key' => $report->key(),
                'name' => $report->name(),
            ],
            'period' => [
                'from' => $parameters->from->toDateString(),
                'to' => $parameters->to->toDateString(),
            ],
            'generated_at' => now()->toIso8601String(),
            'rows' => $artifact->rowCount,
            // Shipped with the figures so a receiver storing them keeps the
            // caveats too.
            'notes' => $artifact->notes,
            'format' => $artifact->format,
            'filename' => $artifact->filename,
            'checksum' => $artifact->checksum(),
            // The rendered report itself: parsed JSON when that is the format,
            // the CSV text otherwise. Re-encoding a JSON string as a string
            // would make every receiver decode it twice.
            'content' => $artifact->format === 'json'
                ? json_decode($artifact->contents, true)
                : $artifact->contents,
        ];
    }
}
