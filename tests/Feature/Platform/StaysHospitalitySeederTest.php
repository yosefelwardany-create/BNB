<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Domain\Organization\Models\Organization;
use App\Domain\Properties\Enums\PropertyStatus;
use App\Domain\Properties\Models\Property;
use App\Domain\Properties\Models\PropertyPhoto;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Users\Models\Membership;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\StaysHospitalitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Stays Hospitality: their properties and photos from their booking site,
 * sample activity around them, and nothing sent anywhere.
 */
class StaysHospitalitySeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();
        Artisan::call('amenities:sync');
    }

    public function test_the_account_holds_their_properties_photos_and_sample_activity(): void
    {
        $this->seed(StaysHospitalitySeeder::class);

        $organization = $this->organization();
        app(TenantContext::class)->runAs($organization, function () use ($organization): void {
            $this->assertSame('EGP', $organization->base_currency);
            $this->assertSame('Africa/Cairo', $organization->timezone);

            $properties = Property::query()->get();
            $this->assertCount(25, $properties);

            foreach ($properties as $property) {
                $this->assertSame('EGP', $property->currency);
                $this->assertSame('EG', $property->country_code);
            }

            // Fully described properties are on sale with a sample rate; the
            // headline-only ones stay drafts, with the missing rate as the reason.
            $onSale = $properties->where('status', PropertyStatus::Active);
            $this->assertCount(19, $onSale);
            $this->assertTrue($onSale->every(fn (Property $property): bool => (int) $property->base_rate > 0));
            $this->assertTrue($properties->where('status', '!=', PropertyStatus::Active)
                ->every(fn (Property $property): bool => (int) $property->base_rate === 0));

            $photos = PropertyPhoto::query()->get();
            $this->assertSame(95, $photos->count());
            $this->assertTrue($photos->every(fn (PropertyPhoto $photo): bool => $photo->disk === 'external'
                && str_starts_with((string) $photo->external_url, 'https://bookingenginecdn.hostaway.com/')));
            $this->assertSame(19, $photos->where('is_cover', true)->count());

            // Sample activity, marked as such.
            $reservations = Reservation::query()->get();
            $this->assertGreaterThan(100, $reservations->count());
            $this->assertTrue($reservations->every(fn (Reservation $r): bool => str_contains((string) $r->internal_notes, 'Sample booking')));
            $this->assertSame(1, $reservations->where('status', ReservationStatus::Cancelled)->count());
            $this->assertGreaterThan(0, $reservations->where('status', ReservationStatus::CheckedIn)->count());
        });

        $this->assertSame(0, Membership::query()->withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())->count());
        Mail::assertNothingSent();
        Notification::assertNothingSent();
    }

    public function test_running_it_again_changes_nothing(): void
    {
        $this->seed(StaysHospitalitySeeder::class);
        $this->seed(StaysHospitalitySeeder::class);

        $this->assertSame(1, app(TenantContext::class)->withoutScope(
            fn () => Organization::query()->where('slug', StaysHospitalitySeeder::SLUG)->count(),
        ));
        app(TenantContext::class)->runAs($this->organization(), function (): void {
            $this->assertCount(25, Property::query()->get());
            $this->assertSame(95, PropertyPhoto::query()->count());
        });
    }

    private function organization(): Organization
    {
        return app(TenantContext::class)->withoutScope(
            fn () => Organization::query()->where('slug', StaysHospitalitySeeder::SLUG)->firstOrFail(),
        );
    }
}
