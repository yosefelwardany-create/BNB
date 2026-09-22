<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Channels\Models\ChannelAccount;
use App\Domain\Channels\Models\ChannelListing;
use App\Domain\Channels\Models\SyncJob;
use App\Domain\Channels\Services\ChannelSynchroniser;
use App\Domain\Channels\Services\ReservationImporter;
use App\Domain\Integrations\DataObjects\ChannelReservationPayload;
use App\Domain\Listings\Models\Listing;
use App\Domain\Organization\Models\Organization;
use App\Domain\Payments\Models\Payment;
use App\Domain\Properties\Models\Property;
use App\Domain\Reservations\DataObjects\ReservationRequest;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Reservations\Services\ReservationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Channel distribution.
 *
 * The outbound direction is about not wasting rate limit and never losing
 * track of whether a push landed. The inbound direction is about accepting
 * bookings we cannot refuse — the guest has paid and the OTA has confirmed —
 * while making any conflict visible to somebody who can act on it.
 */
class ChannelSyncTest extends TestCase
{
    use RefreshDatabase;

    private ChannelSynchroniser $sync;

    private ReservationImporter $importer;

    private Organization $organization;

    private Property $property;

    private Listing $listing;

    private ChannelAccount $account;

    private ChannelListing $mapping;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sync = $this->app->make(ChannelSynchroniser::class);
        $this->importer = $this->app->make(ReservationImporter::class);

        $this->organization = $this->createOrganization(['base_currency' => 'EUR']);

        $this->property = Property::factory()->active()->create([
            'organization_id' => $this->organization->getKey(),
            'currency' => 'EUR',
            'base_rate' => 10000,
            'cleaning_fee' => 0,
            'max_occupancy' => 4,
        ]);

        $this->listing = Listing::factory()->published()->create([
            'organization_id' => $this->organization->getKey(),
            'property_id' => $this->property->getKey(),
            'currency' => 'EUR',
            'minimum_nights' => 1,
        ]);

        $this->account = ChannelAccount::query()->create([
            'organization_id' => $this->organization->getKey(),
            'channel' => 'airbnb',
            'name' => 'Airbnb — main account',
            'status' => ChannelAccount::STATUS_CONNECTED,
            'commission_basis_points' => 1500,
        ]);

        $this->mapping = ChannelListing::query()->create([
            'organization_id' => $this->organization->getKey(),
            'channel_account_id' => $this->account->getKey(),
            'listing_id' => $this->listing->getKey(),
            'property_id' => $this->property->getKey(),
            'external_listing_id' => 'ext-listing-1',
        ]);
    }

    // ------------------------------------------------------------------
    // Pushing out
    // ------------------------------------------------------------------

    public function test_a_local_change_marks_every_mapping_dirty_without_pushing(): void
    {
        $marked = $this->sync->markListingDirty($this->listing->getKey(), 'availability');

        $this->assertSame(1, $marked);

        $this->mapping->refresh();

        $this->assertTrue($this->mapping->availability_dirty);

        // Deliberately cheap: the booking path must never be slowed by however
        // many channels a listing is published to, and a channel being down
        // must not be able to fail a guest's reservation.
        $this->assertSame(0, SyncJob::query()->count());
    }

    public function test_pushing_availability_records_what_was_sent(): void
    {
        $this->sync->markListingDirty($this->listing->getKey(), 'availability');

        $result = $this->sync->pushAvailability($this->mapping->fresh());

        $this->assertNotNull($result);
        $this->assertTrue($result->successful);

        $this->mapping->refresh();

        $this->assertFalse($this->mapping->availability_dirty);
        $this->assertNotNull($this->mapping->availability_hash);
        $this->assertNotNull($this->mapping->availability_pushed_at);
        $this->assertSame(ChannelListing::STATUS_PUBLISHED, $this->mapping->status);

        $job = SyncJob::query()->where('kind', 'availability')->first();

        $this->assertSame(SyncJob::SUCCEEDED, $job->status);
        $this->assertGreaterThan(0, (int) $job->records_sent);
    }

    public function test_an_unchanged_calendar_is_not_pushed_again(): void
    {
        $this->sync->markListingDirty($this->listing->getKey(), 'availability');
        $this->sync->pushAvailability($this->mapping->fresh());

        // Marked dirty again with nothing actually different.
        $this->sync->markListingDirty($this->listing->getKey(), 'availability');

        $second = $this->sync->pushAvailability($this->mapping->fresh());

        // Skipped, not pushed. Re-sending an identical calendar every five
        // minutes is how an account gets throttled out of sending the update
        // that mattered.
        $this->assertNull($second);

        $this->assertSame(
            1,
            SyncJob::query()->where('kind', 'availability')->where('status', SyncJob::SUCCEEDED)->count(),
        );

        $this->assertSame(
            1,
            SyncJob::query()->where('status', SyncJob::SKIPPED)->count(),
        );

        // And the flag is cleared either way, so it is not retried forever.
        $this->assertFalse($this->mapping->fresh()->availability_dirty);
    }

    public function test_a_real_change_is_pushed(): void
    {
        $this->sync->markListingDirty($this->listing->getKey(), 'availability');
        $this->sync->pushAvailability($this->mapping->fresh());

        $firstHash = $this->mapping->fresh()->availability_hash;

        // A booking genuinely changes the calendar.
        $this->book(10, 3);

        $this->sync->markListingDirty($this->listing->getKey(), 'availability');
        $result = $this->sync->pushAvailability($this->mapping->fresh());

        $this->assertNotNull($result);
        $this->assertTrue($result->successful);
        $this->assertNotSame($firstHash, $this->mapping->fresh()->availability_hash);
    }

    public function test_rates_carry_the_mappings_markup(): void
    {
        $this->mapping->forceFill(['rate_adjustment_basis_points' => 1000])->save();

        $this->sync->markListingDirty($this->listing->getKey(), 'rates');

        $result = $this->sync->pushRates($this->mapping->fresh());

        $this->assertNotNull($result);
        $this->assertTrue($result->successful);

        // The same room is often sold dearer where the guest is charged less
        // visibly: 100.00 becomes 110.00 on this channel.
        $this->assertSame(11000, $this->mapping->fresh()->adjustedRate(10000));
    }

    public function test_the_push_window_never_includes_the_past(): void
    {
        $this->mapping->forceFill([
            'availability_dirty' => true,
            'dirty_from' => CarbonImmutable::today()->subDays(30)->toDateString(),
            'dirty_to' => CarbonImmutable::today()->addDays(10)->toDateString(),
        ])->save();

        $this->sync->pushAvailability($this->mapping->fresh());

        $job = SyncJob::query()->where('kind', 'availability')->first();

        // A channel cannot sell yesterday, and some adapters reject a payload
        // that tries.
        $this->assertSame(
            CarbonImmutable::today()->toDateString(),
            $job->range_start->toDateString(),
        );
    }

    public function test_the_dirty_window_widens_rather_than_being_replaced(): void
    {
        $this->mapping->markDirty(
            'availability',
            CarbonImmutable::today()->addDays(10)->toDateString(),
            CarbonImmutable::today()->addDays(20)->toDateString(),
        );

        $this->mapping->fresh()->markDirty(
            'availability',
            CarbonImmutable::today()->addDays(5)->toDateString(),
            CarbonImmutable::today()->addDays(15)->toDateString(),
        );

        $mapping = $this->mapping->fresh();

        // Both changes get pushed. Replacing the bounds would silently drop
        // the first one's dates.
        $this->assertSame(
            CarbonImmutable::today()->addDays(5)->toDateString(),
            $mapping->dirty_from->toDateString(),
        );

        $this->assertSame(
            CarbonImmutable::today()->addDays(20)->toDateString(),
            $mapping->dirty_to->toDateString(),
        );
    }

    public function test_a_sync_job_records_that_the_adapter_is_simulated(): void
    {
        $this->sync->markListingDirty($this->listing->getKey(), 'availability');
        $this->sync->pushAvailability($this->mapping->fresh());

        $job = SyncJob::query()->where('kind', 'availability')->first();

        // "Synchronisation succeeded" must not conceal that nothing left the
        // building. Airbnb has no live adapter until credentials exist.
        $this->assertTrue((bool) $job->is_simulated);
    }

    public function test_a_repeatedly_failing_mapping_is_left_alone(): void
    {
        $this->mapping->forceFill([
            'availability_dirty' => true,
            'consecutive_failures' => 5,
        ])->save();

        $result = $this->sync->pushPending();

        // After a few consecutive failures the cause is almost never
        // transient, and a queue quietly burning retries is how a listing
        // stays wrong for a week with nobody told.
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(0, $result['pushed']);
    }

    public function test_verifying_a_connection_records_the_outcome(): void
    {
        $this->account->forceFill(['credentials' => ['api_key' => 'test-key']])->save();

        $result = $this->sync->verify($this->account->fresh());

        $this->assertTrue($result->successful);

        $this->account->refresh();

        $this->assertSame(ChannelAccount::STATUS_CONNECTED, $this->account->status);
        $this->assertNotNull($this->account->last_verified_at);
    }

    public function test_a_connection_without_credentials_does_not_verify(): void
    {
        // Reporting a credential-less connection as working would leave an
        // operator believing their listings are live on a channel that has
        // never heard of them.
        $result = $this->sync->verify($this->account);

        $this->assertFalse($result->successful);

        $this->account->refresh();

        $this->assertSame(ChannelAccount::STATUS_ERROR, $this->account->status);
        $this->assertNotEmpty($this->account->last_error);
    }

    public function test_credentials_are_encrypted_at_rest(): void
    {
        $this->account->forceFill(['credentials' => ['api_key' => 'sk-super-secret']])->save();

        $raw = DB::table('channel_accounts')
            ->where('id', $this->account->getKey())
            ->value('credentials');

        $this->assertStringNotContainsString('sk-super-secret', (string) $raw);
        $this->assertSame('sk-super-secret', $this->account->fresh()->credential('api_key'));
    }

    // ------------------------------------------------------------------
    // Importing in
    // ------------------------------------------------------------------

    public function test_a_booking_is_imported_with_one_row_per_night(): void
    {
        $outcome = $this->importer->importOne($this->account, $this->payload(
            externalId: 'HMABC123',
            offset: 30,
            nights: 3,
            total: 33000,
            tax: 3000,
        ));

        $this->assertSame('imported', $outcome['result']);

        $reservation = $outcome['reservation'];

        $this->assertSame('airbnb', $reservation->source);
        $this->assertSame(ReservationStatus::Confirmed, $reservation->status);
        $this->assertSame('HMABC123', $reservation->external_reservation_id);

        // Every occupancy, ADR and revenue figure reads these rows: a booking
        // without them is invisible to reporting.
        $this->assertSame(3, $reservation->stayNights()->count());

        // The channel gives a total, not a breakdown. Spread evenly, with the
        // tax excluded because it was never revenue.
        $this->assertSame(30000, (int) $reservation->accommodation_total);
    }

    public function test_the_nightly_split_accounts_for_every_cent(): void
    {
        $outcome = $this->importer->importOne($this->account, $this->payload(
            externalId: 'HMODD',
            offset: 40,
            nights: 3,
            total: 10000,
            tax: 0,
        ));

        $nights = $outcome['reservation']->stayNights()->get();

        // 100.00 over three nights does not divide. Allocated rather than
        // divided, so it comes to exactly 100.00 and not 99.99.
        $this->assertSame(10000, $nights->sum(fn ($night): int => (int) $night->rate_amount));
    }

    public function test_a_redelivered_booking_updates_rather_than_duplicating(): void
    {
        $payload = $this->payload(externalId: 'HMSAME', offset: 30, nights: 3, total: 30000);

        $this->importer->importOne($this->account, $payload);
        $second = $this->importer->importOne($this->account, $payload);

        // Every OTA redelivers. A second booking for the same guest would be
        // an overbooking we invented ourselves.
        $this->assertSame('updated', $second['result']);
        $this->assertSame(1, Reservation::query()->count());
    }

    public function test_an_overlapping_import_is_accepted_and_flagged(): void
    {
        $this->book(50, 3);

        $outcome = $this->importer->importOne($this->account, $this->payload(
            externalId: 'HMCLASH',
            offset: 51,
            nights: 2,
            total: 20000,
        ));

        // Accepted. The guest has paid and the OTA has confirmed; refusing
        // would mean nobody knows about somebody who is arriving.
        $this->assertSame('imported', $outcome['result']);
        $this->assertTrue($outcome['conflicted']);

        $conflict = $outcome['reservation']->metadata['import_conflict'] ?? null;

        // And visible, so a coordinator can move a guest rather than discover
        // it at the door.
        $this->assertNotNull($conflict);
        $this->assertCount(1, $conflict['overlaps']);
    }

    public function test_a_cancellation_on_the_channel_cancels_here(): void
    {
        $this->importer->importOne($this->account, $this->payload(
            externalId: 'HMCANCEL', offset: 30, nights: 3, total: 30000,
        ));

        $outcome = $this->importer->importOne($this->account, $this->payload(
            externalId: 'HMCANCEL', offset: 30, nights: 3, total: 30000, status: 'cancelled',
        ));

        $this->assertSame('cancelled', $outcome['result']);
        $this->assertTrue($outcome['reservation']->status->isCancelled());
    }

    public function test_a_channel_that_collects_payment_does_not_inflate_our_cash(): void
    {
        $this->account->forceFill(['collects_payment' => true])->save();

        $outcome = $this->importer->importOne(
            $this->account->fresh(),
            $this->payload(externalId: 'HMPAID', offset: 30, nights: 2, total: 20000, commission: 3000),
        );

        $payment = Payment::query()->where('reservation_id', $outcome['reservation']->getKey())->first();

        $this->assertNotNull($payment);
        $this->assertSame(20000, (int) $payment->captured_amount);

        // The guest has paid, but the money is with the OTA. Any other
        // treatment inflates the bank balance by every channel booking.
        $this->assertFalse((bool) $payment->is_collected_by_us);

        // And the commission the channel kept is recorded rather than lost.
        $this->assertSame(3000, (int) $payment->fee_amount);
    }

    public function test_a_booking_for_an_unmapped_listing_is_reported_not_guessed(): void
    {
        $outcome = $this->importer->importOne($this->account, $this->payload(
            externalId: 'HMUNKNOWN',
            offset: 30,
            nights: 2,
            total: 20000,
            externalListingId: 'a-listing-nobody-mapped',
        ));

        // Guessing which property it meant would put a guest in the wrong flat.
        $this->assertSame('failed', $outcome['result']);
        $this->assertSame(0, Reservation::query()->count());
    }

    public function test_moving_an_imported_booking_rewrites_its_nights(): void
    {
        $this->importer->importOne($this->account, $this->payload(
            externalId: 'HMMOVE', offset: 30, nights: 3, total: 30000,
        ));

        $outcome = $this->importer->importOne($this->account, $this->payload(
            externalId: 'HMMOVE', offset: 60, nights: 2, total: 20000,
        ));

        $reservation = $outcome['reservation'];

        $this->assertSame(2, $reservation->stayNights()->count());

        // A night row for a date the guest is no longer staying would keep
        // earning revenue that nobody is paying for.
        $this->assertSame(
            CarbonImmutable::today()->addDays(60)->toDateString(),
            $reservation->stayNights()->first()->stay_date->toDateString(),
        );
    }

    // ------------------------------------------------------------------

    private function payload(
        string $externalId,
        int $offset,
        int $nights,
        int $total,
        int $tax = 0,
        int $commission = 0,
        string $status = 'confirmed',
        ?string $externalListingId = null,
    ): ChannelReservationPayload {
        return new ChannelReservationPayload(
            externalReservationId: $externalId,
            externalListingId: $externalListingId ?? 'ext-listing-1',
            status: $status,
            checkIn: CarbonImmutable::today()->addDays($offset)->toDateTimeImmutable(),
            checkOut: CarbonImmutable::today()->addDays($offset + $nights)->toDateTimeImmutable(),
            currency: 'EUR',
            totalAmount: $total,
            payoutAmount: $total - $commission,
            commissionAmount: $commission,
            taxAmount: $tax,
            adults: 2,
            guestFirstName: 'Imported',
            guestLastName: 'Guest',
            guestEmail: 'imported-'.uniqid().'@example.test',
            confirmationCode: $externalId,
        );
    }

    private function book(int $offset, int $nights): Reservation
    {
        return $this->app->make(ReservationService::class)->create(new ReservationRequest(
            listing: $this->listing,
            checkIn: CarbonImmutable::today()->addDays($offset),
            checkOut: CarbonImmutable::today()->addDays($offset + $nights),
            adults: 2,
            status: ReservationStatus::Confirmed,
            guestAttributes: [
                'first_name' => 'Direct',
                'last_name' => 'Guest',
                'email' => 'direct-'.uniqid().'@example.test',
            ],
            bookedAt: CarbonImmutable::today(),
        ));
    }
}
