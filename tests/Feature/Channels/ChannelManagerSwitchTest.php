<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Channels\Models\ChannelAccount;
use App\Domain\Channels\Models\ChannelListing;
use App\Domain\Channels\Services\ReservationImporter;
use App\Domain\Integrations\DataObjects\ChannelReservationPayload;
use App\Domain\Listings\Models\Listing;
use App\Domain\Organization\Models\Organization;
use App\Domain\Properties\Models\Property;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Reservations\Services\ReservationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A property moving from one channel manager to another.
 *
 * The same Airbnb stay comes in again through the new connection, under the
 * new connection's own id but with the same Airbnb code. Matched only on the
 * connection it came through, it was imported a second time: the guest twice,
 * the revenue twice, and the stay flagged as overbooking itself.
 */
class ChannelManagerSwitchTest extends TestCase
{
    use RefreshDatabase;

    private ReservationImporter $importer;

    private Organization $organization;

    private Property $property;

    private Listing $listing;

    private ChannelAccount $before;

    private ChannelAccount $after;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importer = $this->app->make(ReservationImporter::class);
        $this->organization = $this->createOrganization(['base_currency' => 'CAD']);

        [$this->property, $this->listing] = $this->room();
        $this->before = $this->connection('Earlier channel manager');
        $this->after = $this->connection('Channex');
    }

    public function test_a_stay_the_previous_connection_brought_in_is_moved_not_copied(): void
    {
        $this->importer->importOne($this->before, $this->payload('0-HMRMCHPMET-ihsyz4oyf2', 'HMRMCHPMET', -40, 31));
        $original = Reservation::query()->sole();

        // Extended on Airbnb while nothing was listening, then delivered by
        // the new connection under its own id.
        $outcome = $this->importer->importOne($this->after, $this->payload('7d1c0b9e-booking', 'HMRMCHPMET', -40, 52));

        $this->assertSame(1, Reservation::query()->count());
        $this->assertFalse($outcome['conflicted']);

        $moved = $original->fresh();
        $this->assertSame($this->after->getKey(), $moved->channel_account_id);
        $this->assertSame('7d1c0b9e-booking', $moved->external_reservation_id);
        $this->assertSame('HMRMCHPMET', $moved->external_confirmation_code);
        $this->assertSame(CarbonImmutable::today()->addDays(12)->toDateString(), $moved->check_out_date->toDateString());
        $this->assertSame($original->guest_id, $moved->guest_id);
        $this->assertSame($original->confirmation_code, $moved->confirmation_code);

        // Where it was before is kept, so the move can be traced.
        $this->assertSame('0-HMRMCHPMET-ihsyz4oyf2', $moved->metadata['moved_from']['external_reservation_id'] ?? null);
        $this->assertSame($this->before->getKey(), $moved->metadata['moved_from']['channel_account_id'] ?? null);

        // From here on it is the new connection's booking: a redelivery
        // updates it rather than looking for it elsewhere.
        $this->importer->importOne($this->after, $this->payload('7d1c0b9e-booking', 'HMRMCHPMET', -40, 52));
        $this->assertSame(1, Reservation::query()->count());
    }

    public function test_a_stay_cancelled_before_the_move_is_not_revived(): void
    {
        $this->importer->importOne($this->before, $this->payload('0-HM48295CHC-a', 'HM48295CHC', 5, 30));
        $cancelled = Reservation::query()->sole();
        $this->app->make(ReservationService::class)->cancel($cancelled, 'Cancelled on the channel');

        $this->importer->importOne($this->after, $this->payload('c2f0-booking', 'HM48295CHC', 5, 30));

        $this->assertTrue($cancelled->fresh()->status->isCancelled());
        $this->assertSame($this->before->getKey(), $cancelled->fresh()->channel_account_id);
        $this->assertSame(2, Reservation::query()->count());
    }

    public function test_the_same_code_on_another_room_is_not_taken(): void
    {
        [$otherProperty, $otherListing] = $this->room();
        $this->map($this->before, $otherListing, 'other-room');

        $this->importer->importOne($this->before, $this->payload('0-HMX-a', 'HMX5F8DWAE', 5, 30, 'other-room'));
        $elsewhere = Reservation::query()->sole();

        $this->importer->importOne($this->after, $this->payload('b9-booking', 'HMX5F8DWAE', 5, 30));

        $this->assertSame(2, Reservation::query()->count());
        $this->assertSame($this->before->getKey(), $elsewhere->fresh()->channel_account_id);
        $this->assertSame($otherProperty->getKey(), $elsewhere->fresh()->property_id);
    }

    // ------------------------------------------------------------------

    /**
     * @return array{0: Property, 1: Listing}
     */
    private function room(): array
    {
        $property = Property::factory()->active()->create([
            'organization_id' => $this->organization->getKey(),
            'currency' => 'CAD',
            'base_rate' => 5900,
            'max_occupancy' => 1,
        ]);

        $listing = Listing::factory()->published()->create([
            'organization_id' => $this->organization->getKey(),
            'property_id' => $property->getKey(),
            'currency' => 'CAD',
            'minimum_nights' => 1,
        ]);

        return [$property, $listing];
    }

    private function connection(string $name): ChannelAccount
    {
        $account = ChannelAccount::query()->create([
            'organization_id' => $this->organization->getKey(),
            'channel' => 'channex',
            'name' => $name,
            'status' => ChannelAccount::STATUS_CONNECTED,
        ]);

        $this->map($account, $this->listing, 'room-type-'.$account->getKey());

        return $account;
    }

    private function map(ChannelAccount $account, Listing $listing, string $external): void
    {
        ChannelListing::query()->create([
            'organization_id' => $this->organization->getKey(),
            'channel_account_id' => $account->getKey(),
            'listing_id' => $listing->getKey(),
            'property_id' => $listing->property_id,
            'external_listing_id' => $external,
        ]);
    }

    private function payload(string $id, string $code, int $offset, int $nights, ?string $external = null): ChannelReservationPayload
    {
        return new ChannelReservationPayload(
            externalReservationId: $id,
            externalListingId: $external ?? 'room-type-'.(str_starts_with($id, '0-') ? $this->before->getKey() : $this->after->getKey()),
            status: 'confirmed',
            checkIn: CarbonImmutable::today()->addDays($offset)->toDateTimeImmutable(),
            checkOut: CarbonImmutable::today()->addDays($offset + $nights)->toDateTimeImmutable(),
            currency: 'CAD',
            totalAmount: 4700 * $nights,
            adults: 1,
            guestFirstName: 'Ana',
            guestLastName: 'Guest',
            guestEmail: 'ana@example.test',
            confirmationCode: $code,
        );
    }
}
