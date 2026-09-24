<?php

declare(strict_types=1);

namespace App\Domain\Reports\Destinations;

use App\Domain\Documents\Models\Document;
use App\Domain\Reports\Contracts\ReportDestinationInterface;
use App\Domain\Reports\Contracts\ReportInterface;
use App\Domain\Reports\DataObjects\ReportArtifact;
use App\Domain\Reports\DataObjects\ReportDeliveryOutcome;
use App\Domain\Reports\Models\SavedReport;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * A report kept as a file, on the report itself.
 *
 * The destination for the question every scheduled report eventually gets:
 * "what did this say in March?" An email can be deleted and a webhook receiver
 * can drop a message, so a run that is only sent is a run whose output nobody
 * can go back to. This keeps each one as a document against the saved report,
 * which is also how a customer on an S3-backed disk gets their reports into
 * their own bucket without a second integration.
 *
 * Every run is a new file. Overwriting the last one would mean a report that
 * changed cannot be shown to have changed, which is the whole reason somebody
 * asks.
 *
 * Retention is a number of days, applied on write: older runs of the same
 * report are removed, both the row and the file. That is the one place in this
 * product where records are deleted on purpose, and it is confined to derived
 * output — a report can be produced again from the reservations, which is not
 * true of anything else the system keeps.
 */
class StoredFileDestination implements ReportDestinationInterface
{
    private const DEFAULT_RETAIN_DAYS = 365;

    public function __construct(private readonly TenantContext $tenancy) {}

    public function key(): string
    {
        return 'storage';
    }

    public function displayName(): string
    {
        return 'Stored file';
    }

    public function describe(): string
    {
        return 'Keeps each run as a file on the report, so an earlier one can be opened again. '
            .'Optionally set how many days to keep.';
    }

    public function problemsWith(array $config): array
    {
        $retain = $config['retain_days'] ?? null;

        if ($retain === null) {
            return [];
        }

        if (! is_numeric($retain) || (int) $retain < 1) {
            return ['Days to keep must be a whole number of at least one.'];
        }

        return [];
    }

    public function deliver(
        SavedReport $saved,
        ReportInterface $report,
        ReportArtifact $artifact,
        array $config,
    ): ReportDeliveryOutcome {
        $organization = $this->tenancy->organizationOrFail();
        $disk = (string) config('filesystems.default');

        $path = sprintf(
            'organizations/%s/reports/%s/%s-%s',
            $organization->getKey(),
            $saved->getKey(),
            CarbonImmutable::now()->format('Y-m-d-His'),
            $artifact->filename,
        );

        try {
            Storage::disk($disk)->put($path, $artifact->contents);
        } catch (\Throwable $exception) {
            return ReportDeliveryOutcome::failed(
                $this->key(),
                $disk,
                sprintf('The file could not be written: %s', $exception->getMessage()),
            );
        }

        $document = DB::transaction(function () use (
            $saved, $report, $artifact, $organization, $disk, $path, $config
        ): Document {
            $document = Document::query()->create([
                'organization_id' => $organization->getKey(),
                'documentable_type' => $saved->getMorphClass(),
                'documentable_id' => $saved->getKey(),
                'name' => sprintf('%s — %s', $saved->name, CarbonImmutable::now()->toDateString()),
                'kind' => Document::REPORT,
                'disk' => $disk,
                'path' => $path,
                'mime_type' => $artifact->mimeType,
                'size_bytes' => $artifact->sizeBytes(),
                // So a dispute about which version of a figure somebody acted
                // on can be settled by comparing hashes.
                'checksum' => $artifact->checksum(),
                'is_owner_visible' => false,
                'is_guest_visible' => false,
                // A report row can name a guest and what they paid.
                'contains_personal_data' => true,
                'retention_until' => $this->retainUntil($config),
                'metadata' => [
                    'report_key' => $report->key(),
                    'rows' => $artifact->rowCount,
                    'notes' => $artifact->notes,
                ],
            ]);

            $this->prune($saved, $config);

            return $document;
        });

        // A local disk is a real file that a person on that machine can open,
        // so this is a genuine delivery rather than a simulated one — the
        // honesty question here is about *reach*, and the outcome says which
        // disk it landed on.
        return ReportDeliveryOutcome::delivered($this->key(), sprintf('%s:%s', $disk, $path), [
            'document_id' => $document->getKey(),
            'bytes' => $artifact->sizeBytes(),
        ]);
    }

    /**
     * Remove runs of this report that are past their retention.
     *
     * @param  array<string, mixed>  $config
     */
    private function prune(SavedReport $saved, array $config): void
    {
        $expired = Document::query()
            ->where('documentable_type', $saved->getMorphClass())
            ->where('documentable_id', $saved->getKey())
            ->where('kind', Document::REPORT)
            ->whereNotNull('retention_until')
            ->where('retention_until', '<', CarbonImmutable::now())
            ->get();

        foreach ($expired as $document) {
            try {
                Storage::disk((string) $document->disk)->delete((string) $document->path);
            } catch (\Throwable) {
                // A file already gone is the outcome we wanted. The row is
                // removed either way rather than left pointing at nothing.
            }

            $document->delete();
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function retainUntil(array $config): CarbonImmutable
    {
        $days = isset($config['retain_days']) && is_numeric($config['retain_days'])
            ? max(1, (int) $config['retain_days'])
            : self::DEFAULT_RETAIN_DAYS;

        return CarbonImmutable::now()->addDays($days);
    }
}
