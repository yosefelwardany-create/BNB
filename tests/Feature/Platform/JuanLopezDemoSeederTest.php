<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Domain\Messaging\Models\Message;
use App\Domain\Organization\Models\Organization;
use App\Domain\Owners\Models\Owner;
use App\Domain\Owners\Services\ClientFinancials;
use App\Domain\Properties\Models\Property;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Users\Models\Membership;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\JuanLopezDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The Juan Lopez sample account, which runs against a live platform: full of
 * data, and silent towards the outside world.
 */
class JuanLopezDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();
        Artisan::call('amenities:sync');

        $this->seed(JuanLopezDemoSeeder::class);

        $this->organization = app(TenantContext::class)->withoutScope(
            fn () => Organization::query()->where('slug', JuanLopezDemoSeeder::SLUG)->firstOrFail(),
        );

        $this->actingForOrganization($this->organization);
    }

    public function test_the_account_holds_the_five_properties_in_bogota(): void
    {
        $this->assertSame('Juan Lopez', $this->organization->name);
        $this->assertSame('USD', $this->organization->base_currency);

        $properties = Property::query()->orderBy('reference')->get();

        $this->assertSame(
            ['Apto Carrera 7', 'Casa 62', 'Hippies', 'Pachamama', 'Estrella de Oriente'],
            $properties->pluck('name')->all(),
        );

        foreach ($properties as $property) {
            $this->assertSame('America/Bogota', $property->timezone);
            $this->assertSame('USD', $property->currency);
            $this->assertNotEmpty($property->internal_notes);
            $this->assertGreaterThan(0, $property->amenities()->count());
        }

        $this->assertStringContainsString('10 (Tapo app)', (string) $properties->firstWhere('name', 'Pachamama')->internal_notes);
        $this->assertStringContainsString('7 (DMS app)', (string) $properties->firstWhere('name', 'Estrella de Oriente')->internal_notes);
    }

    public function test_every_planned_booking_was_taken(): void
    {
        $this->assertSame(31, Reservation::query()->count());
        $this->assertSame(1, Reservation::query()->where('status', ReservationStatus::Cancelled->value)->count());
        $this->assertGreaterThan(0, Reservation::query()->where('status', ReservationStatus::CheckedIn->value)->count());
        $this->assertGreaterThan(0, Reservation::query()->where('check_in_date', '>', now())->count());
    }

    public function test_it_creates_no_login_and_sends_nothing(): void
    {
        $this->assertSame(0, Membership::query()->withoutGlobalScope('organization')
            ->where('organization_id', $this->organization->getKey())->count());

        $holder = Owner::query()->accountHolder()->firstOrFail();
        $this->assertSame('juan.lopez@example.com', $holder->email);
        $this->assertNull($holder->user_id);

        // The inbox has threads, and none of our replies went through a
        // transport that delivers.
        $this->assertGreaterThan(0, Message::query()->where('direction', Message::INBOUND)->count());
        $this->assertSame(0, Message::query()->where('direction', Message::OUTBOUND)
            ->whereIn('transport', ['email', 'channel', 'sms'])->count());

        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Notification::assertNothingSent();
    }

    public function test_the_client_has_figures_to_read(): void
    {
        $holder = Owner::query()->accountHolder()->firstOrFail();
        $today = CarbonImmutable::today('America/Bogota');

        $figures = app(ClientFinancials::class)->forOwner($holder, $today->subDays(50), $today->addDays(40));

        $this->assertNotEmpty($figures['properties']);
    }

    public function test_a_second_run_changes_nothing(): void
    {
        $before = Reservation::query()->count();

        $this->seed(JuanLopezDemoSeeder::class);

        $this->assertSame($before, Reservation::query()->count());
        $this->assertSame(1, app(TenantContext::class)->withoutScope(
            fn () => Organization::query()->where('slug', JuanLopezDemoSeeder::SLUG)->count(),
        ));
    }
}
