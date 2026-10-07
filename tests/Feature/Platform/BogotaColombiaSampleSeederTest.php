<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Domain\Messaging\Models\Message;
use App\Domain\Organization\Models\Organization;
use App\Domain\Owners\Models\Owner;
use App\Domain\Properties\Models\Property;
use App\Domain\Properties\Models\PropertyPhoto;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\BogotaColombiaSampleSeeder;
use Database\Seeders\DemoSeeder;
use Database\Seeders\JuanLopezDemoSeeder;
use Database\Seeders\RetireDemoHospitalitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\TestCase;

/**
 * The Bogota Colombia sample account, and the removal of the demo account it
 * replaces. Both run on a live platform: the first must send nothing, the
 * second must delete nothing outside the demo account.
 */
class BogotaColombiaSampleSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();
        Artisan::call('amenities:sync');
    }

    public function test_the_account_has_ten_bogota_properties_full_of_data_and_sends_nothing(): void
    {
        $this->seed(BogotaColombiaSampleSeeder::class);
        $organization = $this->organization(BogotaColombiaSampleSeeder::SLUG);
        $this->actingForOrganization($organization);

        $this->assertSame('Bogota Colombia', $organization->name);
        $this->assertSame('USD', $organization->base_currency);

        $properties = Property::query()->get();
        $this->assertCount(10, $properties);

        foreach ($properties as $property) {
            $this->assertSame('Bogotá', $property->city);
            $this->assertSame('America/Bogota', $property->timezone);
            $this->assertNotEmpty($property->neighbourhood);
            $this->assertGreaterThan(0, $property->amenities()->count(), $property->name);

            $photos = PropertyPhoto::query()->where('property_id', $property->getKey())->get();
            $this->assertGreaterThanOrEqual(3, $photos->count(), $property->name);
            $this->assertSame(1, $photos->where('is_cover', true)->count(), $property->name);
            $this->assertTrue($photos->every(fn (PropertyPhoto $photo): bool => $photo->disk === 'external'));
        }

        // Nine stays per property, one skipped each, plus a cancellation.
        $this->assertSame(81, Reservation::query()->count());
        $this->assertSame(1, Reservation::query()->where('status', ReservationStatus::Cancelled->value)->count());
        $this->assertGreaterThan(0, Reservation::query()->where('status', ReservationStatus::CheckedIn->value)->count());

        $this->assertSame(0, Membership::query()->withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())->count());
        $this->assertNull(Owner::query()->accountHolder()->firstOrFail()->user_id);
        $this->assertGreaterThan(0, Message::query()->where('direction', Message::INBOUND)->count());
        $this->assertSame(0, Message::query()->where('direction', Message::OUTBOUND)
            ->whereIn('transport', ['email', 'channel', 'sms'])->count());

        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Notification::assertNothingSent();

        // A second run changes nothing.
        $this->seed(BogotaColombiaSampleSeeder::class);
        $this->assertSame(81, Reservation::query()->count());
    }

    public function test_the_demo_account_and_its_logins_go_and_nothing_else_does(): void
    {
        // A stand-in for the demo account with real depth: the Juan Lopez
        // sample (reservations, ledger, statements, inbox), relabelled.
        $this->seed(JuanLopezDemoSeeder::class);
        $demo = $this->organization(JuanLopezDemoSeeder::SLUG);
        DB::table('organizations')->where('id', $demo->getKey())->update(['slug' => 'demo-hospitality-group']);

        $kept = $this->createTenantWithAdmin()['organization'];

        $this->actingForOrganization($demo);
        $member = $this->createUser($demo, [], ['email' => 'admin@demo-hospitality.test']);
        $this->actingForOrganization($kept);
        $alsoElsewhere = $this->createUser($kept, [], ['email' => 'manager@demo-hospitality.test']);
        $operator = User::query()->create([
            'first_name' => 'Platform',
            'last_name' => 'Operator',
            'email' => 'platform@habitat.test',
            'password' => 'a-long-test-password-1234',
            'status' => 'active',
        ]);
        $owner = $this->createPlatformAdmin(['email' => 'owner@demo-hospitality.test']);

        $this->seed(BogotaColombiaSampleSeeder::class);
        $bogota = $this->organization(BogotaColombiaSampleSeeder::SLUG);
        $bogotaReservations = $this->countFor($bogota, 'reservations');

        $this->seed(RetireDemoHospitalitySeeder::class);

        $this->assertFalse(DB::table('organizations')->where('id', $demo->getKey())->exists());
        foreach (['properties', 'reservations', 'journal_lines', 'owner_statements', 'messages', 'audit_logs'] as $table) {
            $this->assertSame(0, $this->countFor($demo, $table), $table);
        }

        $this->assertFalse(User::withTrashed()->whereKey($member->getKey())->exists());
        $this->assertFalse(User::withTrashed()->whereKey($operator->getKey())->exists());
        // A login that belongs elsewhere, or a platform owner, stays.
        $this->assertTrue(User::query()->whereKey($alsoElsewhere->getKey())->exists());
        $this->assertTrue(User::query()->whereKey($owner->getKey())->exists());

        $this->assertTrue(DB::table('organizations')->where('id', $kept->getKey())->exists());
        $this->assertSame($bogotaReservations, $this->countFor($bogota, 'reservations'));
    }

    public function test_the_demo_account_stays_until_its_replacement_exists(): void
    {
        $this->seed(JuanLopezDemoSeeder::class);
        $demo = $this->organization(JuanLopezDemoSeeder::SLUG);
        DB::table('organizations')->where('id', $demo->getKey())->update(['slug' => 'demo-hospitality-group']);

        $this->seed(RetireDemoHospitalitySeeder::class);

        $this->assertTrue(DB::table('organizations')->where('id', $demo->getKey())->exists());
    }

    #[RequiresPhpExtension('gd')]
    public function test_the_real_demo_seed_is_removed_cleanly(): void
    {
        Storage::fake(config('filesystems.default'));
        $this->seed(DemoSeeder::class);
        $demo = $this->organization('demo-hospitality-group');

        // As on the live platform: the real owner holds the privilege and the
        // demo seed's owner, whose password is published, no longer does.
        $owner = $this->createPlatformAdmin(['email' => 'owner@platform.example.com']);
        User::query()->where('email', 'platform@habitat.test')->firstOrFail()
            ->forceFill(['is_platform_admin' => false])->save();

        $this->seed(BogotaColombiaSampleSeeder::class);
        $this->seed(RetireDemoHospitalitySeeder::class);

        $this->assertFalse(DB::table('organizations')->where('id', $demo->getKey())->exists());
        $this->assertSame(0, User::withTrashed()->where('email', 'like', '%@demo-hospitality.test')->count());
        $this->assertSame(0, User::withTrashed()->where('email', 'platform@habitat.test')->count());
        $this->assertTrue(User::query()->whereKey($owner->getKey())->exists());
    }

    private function organization(string $slug): Organization
    {
        return app(TenantContext::class)->withoutScope(
            fn () => Organization::query()->where('slug', $slug)->firstOrFail(),
        );
    }

    private function countFor(Organization $organization, string $table): int
    {
        return DB::table($table)->where('organization_id', $organization->getKey())->count();
    }
}
