<?php

declare(strict_types=1);

namespace Tests\Feature\Properties;

use App\Domain\Properties\Enums\PropertyStatus;
use App\Domain\Properties\Enums\PropertyType;
use App\Domain\Properties\Events\PropertyActivated;
use App\Domain\Properties\Exceptions\PropertyInUseException;
use App\Domain\Properties\Models\Property;
use App\Domain\Properties\Models\Unit;
use App\Domain\Properties\Services\PropertyService;
use App\Domain\Properties\Services\UnitService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class PropertyServiceTest extends TestCase
{
    use RefreshDatabase;

    private PropertyService $properties;

    private UnitService $units;

    protected function setUp(): void
    {
        parent::setUp();

        $this->properties = $this->app->make(PropertyService::class);
        $this->units = $this->app->make(UnitService::class);
    }

    public function test_a_property_inherits_the_organizations_timezone_and_currency(): void
    {
        $this->createOrganization(['timezone' => 'Europe/Lisbon', 'base_currency' => 'EUR']);

        $property = $this->properties->create([
            'name' => 'Alfama Loft',
            'property_type' => PropertyType::Apartment,
        ]);

        $this->assertSame('Europe/Lisbon', $property->timezone);
        $this->assertSame('EUR', $property->currency);
    }

    public function test_slugs_are_made_unique_within_an_organization(): void
    {
        $this->createOrganization();

        $first = $this->properties->create(['name' => 'Sea View', 'property_type' => PropertyType::Apartment]);
        $second = $this->properties->create(['name' => 'Sea View', 'property_type' => PropertyType::Apartment]);

        $this->assertSame('sea-view', $first->slug);
        $this->assertSame('sea-view-2', $second->slug);
    }

    public function test_activation_is_refused_until_the_property_is_usable(): void
    {
        $this->createOrganization();

        $property = $this->properties->create([
            'name' => 'Half-configured place',
            'property_type' => PropertyType::Apartment,
            'base_rate' => 0,
        ]);

        $blockers = $this->properties->activationBlockers($property);

        $this->assertNotEmpty($blockers);

        $this->expectException(PropertyInUseException::class);
        $this->properties->activate($property);
    }

    public function test_a_complete_property_activates_and_raises_an_event(): void
    {
        Event::fake([PropertyActivated::class]);

        $organization = $this->createOrganization();

        $property = Property::factory()->create([
            'organization_id' => $organization->getKey(),
        ]);

        $activated = $this->properties->activate($property);

        $this->assertSame(PropertyStatus::Active, $activated->status);
        $this->assertNotNull($activated->activated_at);

        Event::assertDispatched(PropertyActivated::class);
    }

    public function test_a_multi_unit_property_cannot_activate_without_a_sellable_unit(): void
    {
        $organization = $this->createOrganization();

        $property = Property::factory()->multiUnit()->create([
            'organization_id' => $organization->getKey(),
        ]);

        $this->assertContains(
            'a multi-unit property needs at least one sellable unit',
            $this->properties->activationBlockers($property),
        );

        $this->units->create($property, ['name' => 'Studio 1', 'code' => '1']);

        $this->assertSame([], $this->properties->activationBlockers($property->refresh()));
    }

    public function test_the_currency_cannot_change_once_the_property_has_traded(): void
    {
        $organization = $this->createOrganization();
        $property = Property::factory()->active()->create(['organization_id' => $organization->getKey()]);

        // A posted ledger line is enough to fix the currency: every historical
        // amount recorded against the property is denominated in it.
        \Illuminate\Support\Facades\DB::table('journal_lines')->insert([
            'id' => (string) \Illuminate\Support\Str::ulid(),
            'organization_id' => $organization->getKey(),
            'journal_entry_id' => $this->createJournalEntry($organization->getKey()),
            'ledger_account_id' => \App\Domain\Accounting\Models\LedgerAccount::query()->firstOrFail()->getKey(),
            'debit' => 1000,
            'credit' => 0,
            'currency' => 'EUR',
            'base_debit' => 1000,
            'base_credit' => 0,
            'base_currency' => 'EUR',
            'exchange_rate' => 1,
            'property_id' => $property->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(PropertyInUseException::class);

        $this->properties->update($property, ['currency' => 'USD']);
    }

    public function test_archiving_is_refused_while_upcoming_reservations_exist(): void
    {
        $organization = $this->createOrganization();
        $property = Property::factory()->active()->create(['organization_id' => $organization->getKey()]);

        $this->createReservation($property, 'confirmed', now()->addDays(10));

        $this->expectException(PropertyInUseException::class);

        $this->properties->archive($property);
    }

    public function test_archiving_keeps_history_and_unpublishes_listings(): void
    {
        $organization = $this->createOrganization();
        $property = Property::factory()->active()->create(['organization_id' => $organization->getKey()]);

        $listing = \App\Domain\Listings\Models\Listing::factory()->published()->create([
            'organization_id' => $organization->getKey(),
            'property_id' => $property->getKey(),
        ]);

        // A past reservation must survive archiving untouched.
        $reservationId = $this->createReservation($property, 'checked_out', now()->subDays(30));

        $this->properties->archive($property, 'Owner sold the property');

        $this->assertSame(PropertyStatus::Archived, $property->refresh()->status);
        $this->assertSame('archived', $listing->refresh()->status->value);

        // The property row and its history are still there.
        $this->assertNotNull(Property::query()->find($property->getKey()));
        $this->assertDatabaseHas('reservations', ['id' => $reservationId]);
    }

    public function test_property_local_time_uses_its_own_timezone(): void
    {
        $organization = $this->createOrganization(['timezone' => 'UTC']);

        $property = Property::factory()->inTimezone('Pacific/Auckland')->create([
            'organization_id' => $organization->getKey(),
        ]);

        CarbonImmutable::setTestNow('2025-06-15 22:00:00');

        // Auckland is ahead of UTC, so its local date has already rolled over.
        $this->assertSame('2025-06-16', $property->localNow()->toDateString());

        // A check-in at 15:00 local is an absolute instant, not 15:00 UTC.
        $checkIn = $property->checkInAt('2025-06-16');
        $this->assertSame('2025-06-16 15:00:00', $checkIn->format('Y-m-d H:i:s'));
        $this->assertSame('2025-06-16T03:00:00+00:00', $checkIn->utc()->toIso8601String());

        CarbonImmutable::setTestNow();
    }

    public function test_rooms_sync_recomputes_bedroom_and_bed_counts(): void
    {
        $organization = $this->createOrganization();
        $property = Property::factory()->create([
            'organization_id' => $organization->getKey(),
            'bedrooms' => 0,
            'beds' => 0,
        ]);

        $this->properties->syncRooms($property, [
            ['name' => 'Master bedroom', 'room_type' => 'bedroom', 'beds' => [['type' => 'king', 'count' => 1]], 'has_ensuite' => true],
            ['name' => 'Second bedroom', 'room_type' => 'bedroom', 'beds' => [['type' => 'single', 'count' => 2]]],
            ['name' => 'Living room', 'room_type' => 'living_room', 'beds' => [['type' => 'sofa_bed', 'count' => 1]]],
        ]);

        $property->refresh();

        $this->assertSame(2, (int) $property->bedrooms);
        $this->assertSame(4, (int) $property->beds);
        $this->assertSame(3, $property->rooms()->count());
    }

    public function test_access_credentials_are_encrypted_at_rest(): void
    {
        $organization = $this->createOrganization();

        $property = $this->properties->create([
            'name' => 'Secure place',
            'property_type' => PropertyType::Apartment,
            'door_code' => '4821#',
            'wifi_password' => 'correct-horse-battery',
        ]);

        $raw = \Illuminate\Support\Facades\DB::table('properties')
            ->where('id', $property->getKey())
            ->first();

        // The stored value must not be the plaintext.
        $this->assertNotSame('4821#', $raw->door_code);
        $this->assertStringNotContainsString('correct-horse', (string) $raw->wifi_password);

        // But the application reads it back correctly.
        $this->assertSame('4821#', $property->refresh()->door_code);
    }

    private function createJournalEntry(string $organizationId): string
    {
        $id = (string) \Illuminate\Support\Str::ulid();

        \Illuminate\Support\Facades\DB::table('journal_entries')->insert([
            'id' => $id,
            'organization_id' => $organizationId,
            'reference' => 'JE-'.substr($id, -6),
            'entry_date' => now()->toDateString(),
            'description' => 'Test entry',
            'source' => 'test',
            'currency' => 'EUR',
            'status' => 'posted',
            'posted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function createReservation(Property $property, string $status, \DateTimeInterface $checkIn): string
    {
        $id = (string) \Illuminate\Support\Str::ulid();
        $checkIn = CarbonImmutable::parse($checkIn);

        \Illuminate\Support\Facades\DB::table('reservations')->insert([
            'id' => $id,
            'organization_id' => $property->organization_id,
            'property_id' => $property->getKey(),
            'confirmation_code' => 'TEST-'.substr($id, -6),
            'status' => $status,
            'source' => 'direct',
            'check_in_date' => $checkIn->toDateString(),
            'check_out_date' => $checkIn->addDays(3)->toDateString(),
            'nights' => 3,
            'adults' => 2,
            'children' => 0,
            'infants' => 0,
            'pets' => 0,
            'currency' => $property->currency,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}
