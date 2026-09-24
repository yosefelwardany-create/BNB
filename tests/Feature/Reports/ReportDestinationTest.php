<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Domain\Documents\Models\Document;
use App\Domain\Organization\Models\Organization;
use App\Domain\Reports\Models\SavedReport;
use App\Domain\Reports\Services\ReportDelivery;
use App\Domain\Reports\Services\ReportDestinationRegistry;
use App\Domain\Reports\Services\ReportRunner;
use App\Domain\Users\Models\User;
use App\Domain\Webhooks\Services\WebhookSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Where a scheduled report goes.
 *
 * Email was the only destination, which is the wrong only destination: an
 * operator who wanted last month's occupancy in their own system was told to
 * receive an attachment and forward it by hand — which is how a figure ends up
 * retyped, and how a retyped figure ends up wrong.
 *
 * Three properties matter more than the transports themselves:
 *
 *  - The report is rendered once, so two destinations in one run cannot show
 *    different figures because a booking landed between them.
 *  - A destination that fails does not take the others down, and the run says
 *    which one failed and why.
 *  - Nothing anybody configured before this existed has to be re-entered.
 */
class ReportDestinationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        ['organization' => $this->organization, 'user' => $this->admin] = $this->createTenantWithAdmin();

        $this->actingAsUser($this->admin, $this->organization);
    }

    private function saved(array $attributes = []): SavedReport
    {
        return SavedReport::query()->create(array_merge([
            'organization_id' => $this->organization->getKey(),
            'name' => 'Monthly occupancy',
            'report_key' => 'occupancy',
            'parameters' => ['period' => 'last_month'],
            'created_by_id' => $this->admin->getKey(),
        ], $attributes));
    }

    private function deliver(SavedReport $saved): array
    {
        ['report' => $report, 'result' => $result] = $this->app->make(ReportRunner::class)
            ->runSaved($saved);

        return $this->app->make(ReportDelivery::class)->send($saved, $report, $result);
    }

    // -----------------------------------------------------------------------
    // Nothing anybody configured breaks
    // -----------------------------------------------------------------------

    public function test_a_report_saved_before_destinations_existed_still_emails_its_recipients(): void
    {
        $saved = $this->saved(['recipients' => ['owner@example.test']]);

        $this->assertSame(
            [['type' => 'email', 'recipients' => ['owner@example.test']]],
            $saved->deliveryTargets(),
        );

        $outcome = $this->deliver($saved);

        $this->assertSame(1, $outcome['sent']);
        $this->assertSame(0, $outcome['failed']);
    }

    public function test_a_report_with_nowhere_to_go_delivers_nothing_and_says_so(): void
    {
        $outcome = $this->deliver($this->saved());

        $this->assertSame(0, $outcome['sent']);
        $this->assertSame([], $outcome['deliveries']);
    }

    // -----------------------------------------------------------------------
    // The webhook
    // -----------------------------------------------------------------------

    public function test_a_report_can_be_posted_to_a_url(): void
    {
        Http::fake(['reports.example.test/*' => Http::response(['ok' => true], 202)]);

        $saved = $this->saved([
            'destinations' => [[
                'type' => 'webhook',
                'url' => 'https://reports.example.test/ingest',
                'secret' => str_repeat('s', 32),
            ]],
        ]);

        $outcome = $this->deliver($saved);

        $this->assertSame(1, $outcome['sent']);

        Http::assertSent(function (Request $request) use ($saved): bool {
            $body = $request->data();

            return $request->url() === 'https://reports.example.test/ingest'
                && $body['report']['key'] === 'occupancy'
                && $body['saved_report']['id'] === $saved->getKey()
                // The caveats travel with the figures, so a receiver storing
                // them keeps what they exclude.
                && $body['notes'] !== []
                && isset($body['checksum'], $body['content']);
        });
    }

    public function test_the_payload_is_signed_with_the_same_scheme_as_every_other_webhook(): void
    {
        Http::fake(['reports.example.test/*' => Http::response([], 200)]);

        $secret = str_repeat('s', 32);

        $this->deliver($this->saved([
            'destinations' => [[
                'type' => 'webhook',
                'url' => 'https://reports.example.test/ingest',
                'secret' => $secret,
            ]],
        ]));

        $signer = $this->app->make(WebhookSigner::class);

        // A receiver already verifying our events verifies this with the code
        // it already has.
        Http::assertSent(fn (Request $request): bool => $signer->verify(
            $request->body(),
            $request->header(WebhookSigner::HEADER)[0] ?? '',
            $secret,
        ));
    }

    public function test_a_receiver_that_refuses_is_recorded_rather_than_thrown(): void
    {
        Http::fake(['reports.example.test/*' => Http::response('no', 500)]);

        $outcome = $this->deliver($this->saved([
            'destinations' => [['type' => 'webhook', 'url' => 'https://reports.example.test/ingest']],
        ]));

        $this->assertSame(0, $outcome['sent']);
        $this->assertSame(1, $outcome['failed']);
        $this->assertStringContainsString('answered 500', $outcome['deliveries'][0]['detail']);
    }

    public function test_a_private_address_is_refused_before_anything_is_sent(): void
    {
        Http::fake();

        $problems = $this->app->make(ReportDestinationRegistry::class)
            ->make('webhook')
            ->problemsWith(['url' => 'https://169.254.169.254/latest/meta-data/']);

        // A URL supplied by a user and fetched by our server is the shape of a
        // server-side request forgery, and the cloud metadata service is the
        // classic target.
        $this->assertNotEmpty($problems);

        Http::assertNothingSent();
    }

    public function test_a_short_secret_is_refused_because_it_is_worse_than_none(): void
    {
        $problems = $this->app->make(ReportDestinationRegistry::class)
            ->make('webhook')
            ->problemsWith(['url' => 'https://reports.example.test/ingest', 'secret' => 'short']);

        // The receiver believes it is verifying something.
        $this->assertNotEmpty($problems);
    }

    // -----------------------------------------------------------------------
    // The stored file
    // -----------------------------------------------------------------------

    public function test_a_run_can_be_kept_as_a_file_on_the_report(): void
    {
        Storage::fake(config('filesystems.default'));

        $saved = $this->saved(['destinations' => [['type' => 'storage']]]);

        $outcome = $this->deliver($saved);

        $this->assertSame(1, $outcome['sent']);

        $document = Document::query()
            ->where('documentable_id', $saved->getKey())
            ->where('kind', Document::REPORT)
            ->first();

        $this->assertNotNull($document);
        $this->assertSame('text/csv', $document->mime_type);

        // Not owner- or guest-visible: a report row can name a guest and what
        // they paid.
        $this->assertFalse((bool) $document->is_owner_visible);
        $this->assertFalse((bool) $document->is_guest_visible);

        Storage::disk((string) $document->disk)->assertExists((string) $document->path);
    }

    public function test_each_run_is_kept_rather_than_overwriting_the_last(): void
    {
        Storage::fake(config('filesystems.default'));

        $saved = $this->saved(['destinations' => [['type' => 'storage']]]);

        $this->deliver($saved);
        $this->travel(1)->seconds();
        $this->deliver($saved);

        // A report that changed cannot be shown to have changed if the last
        // run overwrote the one before it, which is the whole reason somebody
        // asks.
        $this->assertSame(2, Document::query()
            ->where('documentable_id', $saved->getKey())
            ->where('kind', Document::REPORT)
            ->count());
    }

    // -----------------------------------------------------------------------
    // Several at once
    // -----------------------------------------------------------------------

    public function test_every_destination_gets_the_same_bytes(): void
    {
        Http::fake(['reports.example.test/*' => Http::response([], 200)]);
        Storage::fake(config('filesystems.default'));

        $saved = $this->saved([
            'recipients' => ['owner@example.test'],
            'destinations' => [
                ['type' => 'email', 'recipients' => ['owner@example.test']],
                ['type' => 'webhook', 'url' => 'https://reports.example.test/ingest'],
                ['type' => 'storage'],
            ],
        ]);

        $outcome = $this->deliver($saved);

        $this->assertSame(3, $outcome['sent']);

        $document = Document::query()->where('kind', Document::REPORT)->firstOrFail();

        Http::assertSent(fn (Request $request): bool => $request->data()['checksum'] === $document->checksum);
    }

    public function test_one_failing_destination_does_not_stop_the_others(): void
    {
        Http::fake(['reports.example.test/*' => Http::response('', 503)]);
        Storage::fake(config('filesystems.default'));

        $outcome = $this->deliver($this->saved([
            'destinations' => [
                ['type' => 'webhook', 'url' => 'https://reports.example.test/ingest'],
                ['type' => 'storage'],
            ],
        ]));

        $this->assertSame(1, $outcome['sent']);
        $this->assertSame(1, $outcome['failed']);

        // The file still landed.
        $this->assertSame(1, Document::query()->where('kind', Document::REPORT)->count());
    }

    public function test_an_unknown_destination_is_a_recorded_failure_not_a_crash(): void
    {
        $outcome = $this->deliver($this->saved([
            'destinations' => [['type' => 'carrier_pigeon']],
        ]));

        $this->assertSame(1, $outcome['failed']);
        $this->assertStringContainsString('carrier_pigeon', $outcome['deliveries'][0]['detail']);
    }

    // -----------------------------------------------------------------------
    // Configured before it can fail at three in the morning
    // -----------------------------------------------------------------------

    public function test_the_api_refuses_a_destination_it_cannot_deliver_to(): void
    {
        $this->postJson('/api/v1/reports/saved', [
            'name' => 'Monthly occupancy',
            'report_key' => 'occupancy',
            'destinations' => [['type' => 'webhook', 'url' => 'http://reports.example.test/ingest']],
        ])->assertStatus(422)->assertJsonValidationErrors('destinations.0');
    }

    public function test_the_api_refuses_a_destination_that_does_not_exist(): void
    {
        $this->postJson('/api/v1/reports/saved', [
            'name' => 'Monthly occupancy',
            'report_key' => 'occupancy',
            'destinations' => [['type' => 'carrier_pigeon']],
        ])->assertStatus(422)->assertJsonValidationErrors('destinations.0.type');
    }

    public function test_a_schedule_may_use_a_destination_instead_of_an_email_list(): void
    {
        $this->postJson('/api/v1/reports/saved', [
            'name' => 'Monthly occupancy',
            'report_key' => 'occupancy',
            'schedule_cron' => '0 8 1 * *',
            'destinations' => [['type' => 'storage', 'retain_days' => 90]],
        ])->assertCreated();
    }

    public function test_a_schedule_with_nowhere_to_go_is_still_refused(): void
    {
        $this->postJson('/api/v1/reports/saved', [
            'name' => 'Monthly occupancy',
            'report_key' => 'occupancy',
            'schedule_cron' => '0 8 1 * *',
        ])->assertStatus(422);
    }

    public function test_the_catalogue_says_what_each_destination_needs(): void
    {
        $this->getJson('/api/v1/reports/destinations')
            ->assertOk()
            ->assertJsonPath('data.0.key', 'email')
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.1.description', fn (string $value): bool => str_contains($value, 'HTTPS'));
    }

    public function test_a_signing_secret_is_never_returned(): void
    {
        $saved = $this->saved([
            'destinations' => [[
                'type' => 'webhook',
                'url' => 'https://reports.example.test/ingest',
                'secret' => str_repeat('s', 32),
            ]],
        ]);

        $response = $this->getJson("/api/v1/reports/saved/{$saved->getKey()}")->assertOk();

        // A console that can display a secret turns one compromised operator
        // account into a forged payload at every receiver.
        $this->assertArrayNotHasKey('secret', $response->json('data.destinations.0'));
        $this->assertSame('https://reports.example.test/ingest', $response->json('data.destinations.0.url'));
    }
}
