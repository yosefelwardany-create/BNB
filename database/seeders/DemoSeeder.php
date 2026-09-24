<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Channels\Models\ChannelAccount;
use App\Domain\Channels\Models\ChannelListing;
use App\Domain\Guests\Models\Guest;
use App\Domain\Guests\Services\GuestDirectory;
use App\Domain\Listings\Models\Listing;
use App\Domain\Listings\Services\ListingService;
use App\Domain\Locks\Models\SmartLock;
use App\Domain\Locks\Services\AccessCodeManager;
use App\Domain\Messaging\Models\MessageTemplate;
use App\Domain\Messaging\Services\ConversationService;
use App\Domain\Operations\Enums\TaskKind;
use App\Domain\Operations\Enums\TaskPriority;
use App\Domain\Operations\Models\ChecklistTemplate;
use App\Domain\Operations\Models\Vendor;
use App\Domain\Operations\Services\TaskService;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Services\OrganizationProvisioner;
use App\Domain\OwnerAccounting\Services\OwnerPayoutService;
use App\Domain\OwnerAccounting\Services\OwnerStatementBuilder;
use App\Domain\Owners\Models\ManagementAgreement;
use App\Domain\Owners\Models\Owner;
use App\Domain\Owners\Services\OwnerDirectory;
use App\Domain\Owners\Services\OwnershipLedger;
use App\Domain\Payments\Services\ExpenseService;
use App\Domain\Payments\Services\PaymentService;
use App\Domain\Platform\Models\Plan;
use App\Domain\Platform\Services\TenantAdministration;
use App\Domain\Platform\Support\PlanFeature;
use App\Domain\Pricing\Models\FeeRule;
use App\Domain\Pricing\Models\PricingRule;
use App\Domain\Pricing\Models\RatePlan;
use App\Domain\Pricing\Models\TaxRule;
use App\Domain\Properties\Models\Portfolio;
use App\Domain\Properties\Models\Property;
use App\Domain\Properties\Models\PropertyPhoto;
use App\Domain\Properties\Services\PropertyService;
use App\Domain\Reservations\DataObjects\ReservationRequest;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Reservations\Services\ReservationService;
use App\Domain\Reviews\Models\Review;
use App\Domain\Reviews\Services\ReviewService;
use App\Domain\Upsells\Models\UpsellProduct;
use App\Domain\Users\Models\User;
use App\Domain\Users\Support\RoleRegistry;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * A working portfolio to look at.
 *
 * Every record here is created through the same services the application uses,
 * so the demo obeys the same rules as production: availability is really
 * checked, rates are really calculated, the ledger really balances, and a
 * statement really adds up. Nothing is inserted straight into a table to make
 * a screen look fuller than the data supports.
 *
 * That has a consequence worth stating: this seeder is slow compared with a
 * bulk insert, and it can refuse. If a booking here collides with another, the
 * availability engine rejects it exactly as it would reject a double booking
 * made by a person. That is the point — a demo that can only be produced by
 * bypassing the rules is a demo of something that does not exist.
 *
 * Dates are relative to the day it runs, so the portfolio always has guests in
 * house, arrivals tomorrow, work outstanding and a month of history behind it.
 */
class DemoSeeder extends Seeder
{
    private const ORGANIZATION_SLUG = 'demo-hospitality-group';

    private const PASSWORD = 'password';

    private Organization $organization;

    private CarbonImmutable $today;

    /** @var array<string, Property> */
    private array $properties = [];

    /** @var array<string, Listing> */
    private array $listings = [];

    /** @var array<string, Owner> */
    private array $owners = [];

    /** @var array<string, User> */
    private array $staff = [];

    /** @var list<Reservation> */
    private array $reservations = [];

    private ?User $operator = null;

    public function run(): void
    {
        $tenancy = app(TenantContext::class);

        $existing = $tenancy->withoutScope(
            fn () => Organization::query()->where('slug', self::ORGANIZATION_SLUG)->first(),
        );

        if ($existing !== null) {
            // Never re-seeded on top of itself. A second run would duplicate
            // every booking and every statement, and the correct fix for a
            // stale demo is a fresh database, not a delete pass over records
            // the rest of the system treats as immutable.
            $this->command?->warn(
                'The demo organization already exists. Skipping — drop the database to rebuild it.',
            );

            return;
        }

        $this->today = CarbonImmutable::today('Europe/Lisbon');

        // The permission registry and the amenity catalogue are reference
        // data, not demo data: without them the roles below grant nothing.
        Artisan::call('permissions:sync');
        Artisan::call('amenities:sync');

        $this->seedPlatform();

        $admin = $this->provisionOrganization();

        $tenancy->runAs($this->organization, function () use ($admin): void {
            $this->staff['admin'] = $admin;

            $this->seedStaff();
            $this->seedCatalogue();
            $this->seedPortfolio();
            $this->seedOwners();
            $this->seedChannels();
            $this->seedLocks();
            $this->seedGuestsAndBookings();
            $this->seedOperations();
            $this->issueAccessCodes();
            $this->seedConversations();
            $this->seedCosts();
            $this->seedReviews();
            $this->seedStatements();
        });

        $this->assignPlan();

        $this->drainTheQueue();

        $this->report();
    }

    /**
     * The platform operator's own world: plans, and somebody who can govern it.
     *
     * Created outside any tenant, because that is what it is. Without a platform
     * administrator the console is unreachable, and a demo of a multi-tenant
     * product that cannot show its own console demonstrates half the product.
     */
    private function seedPlatform(): void
    {
        $definitions = [
            [
                'name' => 'Starter',
                'slug' => 'starter',
                'description' => 'For an owner-operator with a handful of properties.',
                'price_amount' => 4900,
                'position' => 10,
                'max_properties' => 5,
                'max_units' => 10,
                'max_listings' => 5,
                'max_users' => 3,
                'max_reservations_per_month' => 100,
                'features' => [
                    PlanFeature::GUEST_PORTAL,
                    PlanFeature::OWNER_PORTAL,
                ],
            ],
            [
                'name' => 'Professional',
                'slug' => 'professional',
                'description' => 'For a management company distributing to channels.',
                'price_amount' => 14900,
                'position' => 20,
                'max_properties' => 50,
                'max_units' => 200,
                'max_listings' => 100,
                'max_users' => 25,
                'max_reservations_per_month' => null,
                'features' => [
                    PlanFeature::GUEST_PORTAL,
                    PlanFeature::OWNER_PORTAL,
                    PlanFeature::CHANNELS,
                    PlanFeature::AUTOMATION,
                    PlanFeature::UPSELLS,
                    PlanFeature::ADVANCED_REPORTING,
                    PlanFeature::SMART_LOCKS,
                ],
            ],
            [
                'name' => 'Portfolio',
                'slug' => 'portfolio',
                'description' => 'Unlimited, with the developer surface and multi-currency.',
                'price_amount' => 49900,
                'position' => 30,
                // Every cap null: unlimited, which is not the same as a large
                // number and is reported as null all the way to the interface.
                'features' => PlanFeature::keys(),
            ],
        ];

        foreach ($definitions as $definition) {
            Plan::query()->create($definition + [
                'currency' => 'EUR',
                'billing_interval' => 'monthly',
                'trial_days' => 30,
            ]);
        }

        $operator = User::query()->create([
            'first_name' => 'Platform',
            'last_name' => 'Operator',
            'email' => 'platform@habitat.test',
            'password' => self::PASSWORD,
            'timezone' => 'UTC',
            'locale' => 'en',
            'status' => 'active',
        ]);

        // Deliberately not mass-assignable on the model — no request may ever
        // set it — so it is written explicitly here.
        $operator->forceFill([
            'is_platform_admin' => true,
            'email_verified_at' => now(),
        ])->save();

        // No membership anywhere. A platform administrator does not need a seat
        // in a customer's company to govern the platform, and giving them one
        // would misrepresent how the console's authorisation works.
        $this->operator = $operator;
    }

    /**
     * The tenant and its first administrator.
     */
    private function provisionOrganization(): User
    {
        $result = app(OrganizationProvisioner::class)->provision(
            [
                'name' => 'Demo Hospitality Group',
                'legal_name' => 'Demo Hospitality Group, Lda.',
                'slug' => self::ORGANIZATION_SLUG,
                'status' => 'active',
                'base_currency' => 'EUR',
                'timezone' => 'Europe/Lisbon',
                'locale' => 'en',
                'country_code' => 'PT',
                'contact_email' => 'hello@demo-hospitality.test',
                'contact_phone' => '+351 210 000 000',
            ],
            [
                'first_name' => 'Ana',
                'last_name' => 'Ribeiro',
                'email' => 'admin@demo-hospitality.test',
                'password' => self::PASSWORD,
                'job_title' => 'Managing Director',
            ],
        );

        $this->organization = $result['organization'];

        return $result['user'];
    }

    /**
     * One person per role, so the separation of duties can actually be seen.
     *
     * A demo with a single administrator shows nothing about authorisation:
     * everything works for them. Signing in as the cleaner is the only way to
     * see that the cleaner cannot read an owner statement.
     */
    private function seedStaff(): void
    {
        $people = [
            'manager' => ['Bruno', 'Costa', 'manager@demo-hospitality.test', RoleRegistry::PROPERTY_MANAGER, 'Property Manager'],
            'operations' => ['Célia', 'Marques', 'operations@demo-hospitality.test', RoleRegistry::OPERATIONS_MANAGER, 'Head of Operations'],
            'agent' => ['Diogo', 'Nunes', 'reservations@demo-hospitality.test', RoleRegistry::RESERVATIONS_AGENT, 'Reservations Agent'],
            'accountant' => ['Eva', 'Lopes', 'accounts@demo-hospitality.test', RoleRegistry::ACCOUNTANT, 'Financial Controller'],
            'cleaner' => ['Filipa', 'Sousa', 'cleaning@demo-hospitality.test', RoleRegistry::CLEANER, 'Housekeeper'],
            'maintenance' => ['Gonçalo', 'Pinto', 'maintenance@demo-hospitality.test', RoleRegistry::MAINTENANCE, 'Maintenance Technician'],
        ];

        $provisioner = app(OrganizationProvisioner::class);

        foreach ($people as $key => [$first, $last, $email, $role, $title]) {
            $user = User::query()->create([
                'first_name' => $first,
                'last_name' => $last,
                'email' => $email,
                'password' => self::PASSWORD,
                'timezone' => 'Europe/Lisbon',
                'locale' => 'en',
                'status' => 'active',
                'email_verified_at' => now(),
            ]);

            $provisioner->attachUser($this->organization, $user, [$role], $title);

            $this->staff[$key] = $user;
        }
    }

    /**
     * Fees, taxes, rate plans and the templates operations runs on.
     */
    private function seedCatalogue(): void
    {
        $organization = $this->organization->getKey();

        RatePlan::query()->create([
            'organization_id' => $organization,
            'name' => 'Standard',
            'slug' => 'standard',
            'description' => 'The published nightly rate, fully flexible.',
            'currency' => 'EUR',
            'derivation_type' => 'independent',
            'is_default' => true,
            'is_active' => true,
            'priority' => 100,
        ]);

        RatePlan::query()->create([
            'organization_id' => $organization,
            'name' => 'Non-refundable',
            'slug' => 'non-refundable',
            'description' => 'Ten per cent below the standard rate, paid in full at booking.',
            'currency' => 'EUR',
            'derivation_type' => 'percentage',
            'derivation_value' => -10,
            'minimum_nights' => 2,
            'is_active' => true,
            'priority' => 90,
        ]);

        FeeRule::query()->create([
            'organization_id' => $organization,
            'name' => 'Cleaning',
            'code' => 'cleaning',
            'kind' => 'cleaning',
            'charge_basis' => 'per_stay',
            'amount' => 6500,
            'currency' => 'EUR',
            'is_taxable' => true,
            'is_refundable' => false,
            'include_in_displayed_rate' => true,
            'is_active' => true,
            'position' => 10,
        ]);

        FeeRule::query()->create([
            'organization_id' => $organization,
            'name' => 'Extra guest',
            'code' => 'extra-guest',
            'kind' => 'extra_guest',
            'charge_basis' => 'per_guest_per_night',
            'amount' => 1500,
            'currency' => 'EUR',
            'applies_after_guests' => 2,
            'is_taxable' => true,
            'is_active' => true,
            'position' => 20,
        ]);

        FeeRule::query()->create([
            'organization_id' => $organization,
            'name' => 'Pet',
            'code' => 'pet',
            'kind' => 'pet',
            'charge_basis' => 'per_stay',
            'amount' => 3000,
            'currency' => 'EUR',
            'is_taxable' => true,
            'is_optional' => true,
            'is_active' => true,
            'position' => 30,
        ]);

        // Lisbon's tourist tax: a flat amount per adult per night, capped at
        // seven nights. The cap is the part that gets implemented wrongly.
        TaxRule::query()->create([
            'organization_id' => $organization,
            'name' => 'Municipal tourist tax',
            'code' => 'pt-lisbon-tourist',
            'description' => 'Lisbon municipal tourist tax, charged per adult per night for the first seven nights.',
            'calculation' => 'per_person_per_night',
            'amount' => 200,
            'currency' => 'EUR',
            'applies_to_accommodation' => true,
            'applies_to_fees' => false,
            'exempt_after_nights' => 7,
            'country_code' => 'PT',
            'city' => 'Lisbon',
            'priority' => 10,
            'is_active' => true,
        ]);

        TaxRule::query()->create([
            'organization_id' => $organization,
            'name' => 'VAT (reduced, accommodation)',
            'code' => 'pt-vat-accommodation',
            'calculation' => 'percentage',
            'rate' => 6,
            'currency' => 'EUR',
            'applies_to_accommodation' => true,
            'applies_to_fees' => true,
            'country_code' => 'PT',
            'priority' => 20,
            'is_active' => true,
        ]);

        ChecklistTemplate::query()->create([
            'organization_id' => $organization,
            'name' => 'Standard turnover',
            'kind' => 'cleaning',
            'description' => 'Between one guest leaving and the next arriving.',
            'items' => [
                ['label' => 'Strip and remake all beds', 'requires_photo' => false],
                ['label' => 'Bathrooms: clean, restock, replace towels', 'requires_photo' => false],
                ['label' => 'Kitchen: empty fridge, run dishwasher, wipe surfaces', 'requires_photo' => false],
                ['label' => 'Check for damage and left property', 'requires_photo' => false],
                // The photograph is the evidence that settles a complaint
                // about the state of a property on arrival.
                ['label' => 'Photograph each finished room', 'requires_photo' => true],
                ['label' => 'Set heating and close windows', 'requires_photo' => false],
            ],
            'is_default' => true,
            'is_active' => true,
        ]);

        ChecklistTemplate::query()->create([
            'organization_id' => $organization,
            'name' => 'Quarterly inspection',
            'kind' => 'inspection',
            'items' => [
                ['label' => 'Test smoke and carbon monoxide alarms', 'requires_photo' => true],
                ['label' => 'Check for damp and water damage', 'requires_photo' => true],
                ['label' => 'Run every tap and flush every WC', 'requires_photo' => false],
                ['label' => 'Inventory check against the schedule', 'requires_photo' => false],
            ],
            'is_active' => true,
        ]);

        Vendor::query()->create([
            'organization_id' => $organization,
            'name' => 'Lumière Cleaning',
            'category' => 'cleaning',
            'contact_name' => 'Helena Dias',
            'email' => 'contact@lumiere-cleaning.test',
            'phone' => '+351 211 111 111',
            'city' => 'Lisbon',
            'country_code' => 'PT',
            'hourly_rate' => 1800,
            'currency' => 'EUR',
            'is_active' => true,
        ]);

        Vendor::query()->create([
            'organization_id' => $organization,
            'name' => 'Tejo Plumbing',
            'category' => 'plumbing',
            'contact_name' => 'Rui Valente',
            'email' => 'jobs@tejo-plumbing.test',
            'phone' => '+351 211 222 222',
            'city' => 'Lisbon',
            'country_code' => 'PT',
            'callout_fee' => 4500,
            'currency' => 'EUR',
            'is_active' => true,
        ]);

        MessageTemplate::query()->create([
            'organization_id' => $organization,
            'name' => 'Booking confirmed',
            'code' => 'booking-confirmed',
            'category' => 'confirmation',
            'subject' => 'Your booking at {{ property.name }} is confirmed',
            'body' => "Dear {{ guest.first_name }},\n\n"
                ."Your stay at {{ property.name }} is confirmed for {{ reservation.check_in }} to {{ reservation.check_out }}.\n\n"
                ."Your confirmation code is {{ reservation.confirmation_code }}.\n\n"
                ."We will send directions and access details closer to your arrival.\n\n"
                .'Demo Hospitality Group',
            'language' => 'en',
            'transport' => 'email',
            'is_active' => true,
        ]);

        MessageTemplate::query()->create([
            'organization_id' => $organization,
            'name' => 'Arrival details',
            'code' => 'arrival-details',
            'category' => 'pre_arrival',
            'subject' => 'Getting into {{ property.name }}',
            'body' => "Hello {{ guest.first_name }},\n\n"
                ."You arrive tomorrow. Check-in is from {{ property.check_in_time }}.\n\n"
                ."{{ property.check_in_instructions }}\n\n"
                .'Safe travels.',
            'language' => 'en',
            'transport' => 'email',
            'is_active' => true,
        ]);

        UpsellProduct::query()->create([
            'organization_id' => $organization,
            'name' => 'Early check-in (from 11:00)',
            'code' => 'early-check-in',
            'description' => 'Subject to the property being ready.',
            'kind' => 'early_check_in',
            'price' => 3500,
            'currency' => 'EUR',
            'charge_basis' => 'per_stay',
            'is_taxable' => true,
            'lead_time_hours' => 24,
            // Approved by a person, because it depends on whether the previous
            // guest has left and the clean has finished.
            'requires_approval' => true,
            'daily_capacity' => 3,
            'is_active' => true,
            'position' => 10,
        ]);

        UpsellProduct::query()->create([
            'organization_id' => $organization,
            'name' => 'Airport transfer (up to 3 people)',
            'code' => 'airport-transfer',
            'kind' => 'transfer',
            'price' => 4000,
            'currency' => 'EUR',
            'charge_basis' => 'per_stay',
            'is_taxable' => true,
            'lead_time_hours' => 48,
            'daily_capacity' => 6,
            'requires_approval' => true,
            'is_active' => true,
            'position' => 20,
        ]);

        UpsellProduct::query()->create([
            'organization_id' => $organization,
            'name' => 'Mid-stay clean',
            'code' => 'mid-stay-clean',
            'kind' => 'cleaning',
            'price' => 5500,
            'currency' => 'EUR',
            'charge_basis' => 'per_stay',
            'is_taxable' => true,
            'lead_time_hours' => 24,
            'daily_capacity' => 4,
            'requires_approval' => false,
            'is_active' => true,
            'position' => 30,
        ]);
    }

    /**
     * Two portfolios: city apartments, and a small aparthotel.
     *
     * The aparthotel is multi-unit deliberately — a portfolio of whole-home
     * rentals never exercises unit types, unit allocation or the inventory
     * arithmetic that goes with them, and those are where a property system
     * quietly gets availability wrong.
     */
    private function seedPortfolio(): void
    {
        $organization = $this->organization->getKey();
        $properties = app(PropertyService::class);
        $listings = app(ListingService::class);

        $city = Portfolio::query()->create([
            'organization_id' => $organization,
            'name' => 'Lisbon city apartments',
            'slug' => 'lisbon-city-apartments',
            'description' => 'Whole apartments in central Lisbon.',
            'color' => '#4f46e5',
            'is_active' => true,
        ]);

        $coast = Portfolio::query()->create([
            'organization_id' => $organization,
            'name' => 'Cascais coast',
            'slug' => 'cascais-coast',
            'description' => 'Coastal properties west of Lisbon.',
            'color' => '#0ea5e9',
            'is_active' => true,
        ]);

        $definitions = [
            'alfama' => [
                'portfolio' => $city,
                'name' => 'Alfama Terrace Apartment',
                'type' => 'apartment',
                'address' => ['Rua dos Remédios 84', 'Lisbon', '1100-443'],
                'bedrooms' => 2, 'bathrooms' => 1, 'beds' => 3, 'occupancy' => 4,
                'rate' => 14500,
                'summary' => 'A two-bedroom apartment with a tiled terrace above the Alfama rooftops.',
            ],
            'principe' => [
                'portfolio' => $city,
                'name' => 'Príncipe Real Loft',
                'type' => 'loft',
                'address' => ['Rua da Escola Politécnica 212', 'Lisbon', '1250-100'],
                'bedrooms' => 1, 'bathrooms' => 1, 'beds' => 2, 'occupancy' => 3,
                'rate' => 12000,
                'summary' => 'A one-bedroom loft a minute from the Príncipe Real garden.',
            ],
            'baixa' => [
                'portfolio' => $city,
                'name' => 'Baixa Riverside Two-Bed',
                'type' => 'apartment',
                'address' => ['Rua da Prata 31', 'Lisbon', '1100-414'],
                'bedrooms' => 2, 'bathrooms' => 2, 'beds' => 3, 'occupancy' => 5,
                'rate' => 16500,
                'summary' => 'Two bedrooms and two bathrooms between the Praça do Comércio and Rossio.',
            ],
            'estoril' => [
                'portfolio' => $coast,
                'name' => 'Estoril Garden House',
                'type' => 'house',
                'address' => ['Avenida de Sintra 140', 'Estoril', '2765-191'],
                'city' => 'Estoril',
                'bedrooms' => 3, 'bathrooms' => 2, 'beds' => 5, 'occupancy' => 6,
                'rate' => 22000,
                'summary' => 'A three-bedroom house with a walled garden, ten minutes from Tamariz beach.',
            ],
        ];

        foreach ($definitions as $key => $definition) {
            $property = $properties->create([
                'portfolio_id' => $definition['portfolio']->getKey(),
                'name' => $definition['name'],
                'property_type' => $definition['type'],
                'rental_kind' => 'entire_place',
                'address_line_1' => $definition['address'][0],
                'city' => $definition['city'] ?? $definition['address'][1],
                'postal_code' => $definition['address'][2],
                'country_code' => 'PT',
                'timezone' => 'Europe/Lisbon',
                'currency' => 'EUR',
                'bedrooms' => $definition['bedrooms'],
                'bathrooms' => $definition['bathrooms'],
                'beds' => $definition['beds'],
                'max_occupancy' => $definition['occupancy'],
                'summary' => $definition['summary'],
                'description' => $definition['summary'],
                'house_rules' => 'No parties or events. No smoking indoors. Quiet between 22:00 and 08:00.',
                'check_in_time' => '15:00',
                'check_out_time' => '11:00',
                'check_in_method' => 'lockbox',
                'check_in_instructions' => 'The lockbox is to the right of the main door. We send the code the day before arrival.',
                'base_rate' => $definition['rate'],
                'cleaning_fee' => 6500,
                'security_deposit' => 20000,
                'minimum_nights' => 2,
                'cleaning_duration_minutes' => 150,
                'preparation_hours' => 4,
                'instant_book' => true,
            ]);

            $properties->activate($property);

            // Backdated, because the demo carries a year of bookings and
            // occupancy is now measured against the nights a property actually
            // owned. A portfolio activated today with a stay last March would
            // report an occupancy of nothing over nothing.
            $property->forceFill([
                'activated_at' => CarbonImmutable::today()->subYear()->startOfYear(),
            ])->save();

            $listing = $listings->create($property, [
                'title' => $definition['name'],
                'summary' => $definition['summary'],
                'description' => $definition['summary'],
                'max_occupancy' => $definition['occupancy'],
                'bedrooms' => $definition['bedrooms'],
                'bathrooms' => $definition['bathrooms'],
                'beds' => $definition['beds'],
                'base_rate' => $definition['rate'],
                'cleaning_fee' => 6500,
                'minimum_nights' => 2,
                'instant_book' => true,
            ]);

            // Publication really requires a photo, so the demo really supplies
            // one. See {@see photographFor()} for what it is and is not.
            $this->photographFor($property, $definition['name']);

            $listings->publish($listing->fresh());

            $this->properties[$key] = $property->fresh();
            $this->listings[$key] = $listing->fresh();
        }

        $this->seedPricingRules();
    }

    /**
     * A real image file for a property.
     *
     * Publishing a listing requires a photograph, and that rule is not relaxed
     * for the demo. What is written here is an honestly labelled placeholder —
     * a generated card bearing the property's name — stored on the configured
     * disk like any upload, with its true dimensions and byte size recorded.
     *
     * It is not a photograph of the property, and the caption says so. Sourcing
     * images of real apartments for a demo would mean either using somebody's
     * copyrighted photographs or implying these addresses exist.
     */
    private function photographFor(Property $property, string $label): void
    {
        $width = 1200;
        $height = 800;

        $image = imagecreatetruecolor($width, $height);

        if ($image === false) {
            $this->command?->warn('GD is unavailable; the demo listings cannot be published.');

            return;
        }

        // A soft vertical wash, derived from the property name so that each
        // one is visibly distinct in a grid.
        $seed = crc32($property->name);
        $hue = [($seed % 90) + 30, (($seed >> 8) % 90) + 60, (($seed >> 16) % 90) + 110];

        for ($y = 0; $y < $height; $y++) {
            $shade = $y / $height;

            $colour = imagecolorallocate(
                $image,
                (int) min(255, $hue[0] + $shade * 90),
                (int) min(255, $hue[1] + $shade * 80),
                (int) min(255, $hue[2] + $shade * 70),
            );

            imagefilledrectangle($image, 0, $y, $width, $y, $colour ?: 0);
        }

        $ink = imagecolorallocate($image, 255, 255, 255) ?: 0;

        imagestring($image, 5, 48, $height - 110, $label, $ink);
        imagestring($image, 3, 48, $height - 80, 'Demo placeholder - not a photograph of this property', $ink);

        $directory = sprintf(
            'organizations/%s/properties/%s',
            $property->organization_id,
            $property->getKey(),
        );

        $temporary = tempnam(sys_get_temp_dir(), 'demo-photo');

        imagejpeg($image, $temporary, 82);
        imagedestroy($image);

        $disk = config('filesystems.default');
        $path = $directory.'/'.Str::ulid()->toBase32().'.jpg';

        Storage::disk($disk)->put($path, (string) file_get_contents($temporary));

        $bytes = (int) filesize($temporary);

        @unlink($temporary);

        PropertyPhoto::query()->create([
            'organization_id' => $property->organization_id,
            'property_id' => $property->getKey(),
            'disk' => $disk,
            'path' => $path,
            'original_filename' => Str::slug($label).'.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => $bytes,
            'width' => $width,
            'height' => $height,
            'caption' => 'Placeholder image — this is not a photograph of the property.',
            'position' => 1,
            'is_cover' => true,
        ]);
    }

    /**
     * Rates that move, because a flat rate all year is not a real portfolio.
     */
    private function seedPricingRules(): void
    {
        $organization = $this->organization->getKey();

        PricingRule::query()->create([
            'organization_id' => $organization,
            'name' => 'Weekend uplift',
            'description' => 'Friday and Saturday nights carry a premium.',
            'kind' => 'day_of_week',
            'days_of_week' => [5, 6],
            'adjustment_type' => 'percentage',
            'adjustment_value' => 20,
            'priority' => 10,
            'is_active' => true,
        ]);

        PricingRule::query()->create([
            'organization_id' => $organization,
            'name' => 'High season',
            'description' => 'June to September.',
            'kind' => 'seasonal',
            'stay_from' => $this->today->setMonth(6)->setDay(1)->toDateString(),
            'stay_to' => $this->today->setMonth(9)->setDay(30)->toDateString(),
            'adjustment_type' => 'percentage',
            'adjustment_value' => 25,
            'priority' => 20,
            'is_active' => true,
        ]);

        PricingRule::query()->create([
            'organization_id' => $organization,
            'name' => 'Weekly discount',
            'description' => 'Seven nights or more.',
            'kind' => 'length_of_stay',
            'conditions' => ['minimum_nights' => 7],
            'adjustment_type' => 'percentage',
            'adjustment_value' => -12,
            // A floor, so a stacked discount can never take a night below the
            // cost of servicing it.
            'floor_rate' => 8000,
            'priority' => 30,
            'is_active' => true,
        ]);

        PricingRule::query()->create([
            'organization_id' => $organization,
            'name' => 'Last-minute',
            'description' => 'Within seven days of arrival.',
            'kind' => 'last_minute',
            'conditions' => ['days_before_arrival' => 7],
            'adjustment_type' => 'percentage',
            'adjustment_value' => -15,
            'floor_rate' => 8000,
            'priority' => 40,
            'is_active' => true,
        ]);
    }

    /**
     * Owners, their shares and the terms they are managed under.
     */
    private function seedOwners(): void
    {
        $directory = app(OwnerDirectory::class);
        $ledger = app(OwnershipLedger::class);

        $definitions = [
            'ferreira' => [
                'attributes' => [
                    'type' => 'individual',
                    'first_name' => 'Helena',
                    'last_name' => 'Ferreira',
                    'email' => 'helena.ferreira@owners.test',
                    'phone' => '+351 912 000 001',
                    'country_code' => 'PT',
                    'payout_currency' => 'EUR',
                    'payout_method' => 'bank_transfer',
                    'statement_frequency' => 'monthly',
                    'statement_day' => 1,
                ],
                'holdings' => [['alfama', 100.0], ['principe', 100.0]],
                'commission' => 18.0,
            ],
            'marchetti' => [
                'attributes' => [
                    'type' => 'company',
                    'company_name' => 'Marchetti Investimentos',
                    'first_name' => 'Luca',
                    'last_name' => 'Marchetti',
                    'email' => 'luca@marchetti-invest.test',
                    'phone' => '+351 912 000 002',
                    'country_code' => 'PT',
                    'payout_currency' => 'EUR',
                    'payout_method' => 'bank_transfer',
                    'statement_frequency' => 'monthly',
                    'statement_day' => 1,
                ],
                // A jointly held property, because the half of statement logic
                // that splits revenue by share is never exercised otherwise.
                'holdings' => [['baixa', 60.0]],
                'commission' => 20.0,
            ],
            'okafor' => [
                'attributes' => [
                    'type' => 'individual',
                    'first_name' => 'Ngozi',
                    'last_name' => 'Okafor',
                    'email' => 'ngozi.okafor@owners.test',
                    'phone' => '+44 7700 900002',
                    'country_code' => 'GB',
                    'payout_currency' => 'EUR',
                    'payout_method' => 'bank_transfer',
                    'statement_frequency' => 'monthly',
                    'statement_day' => 1,
                ],
                'holdings' => [['baixa', 40.0], ['estoril', 100.0]],
                'commission' => 20.0,
            ],
        ];

        foreach ($definitions as $key => $definition) {
            $owner = $directory->create($definition['attributes']);

            foreach ($definition['holdings'] as [$propertyKey, $share]) {
                $ledger->assign($this->properties[$propertyKey], $owner, [
                    'ownership_percentage' => $share,
                    'is_primary' => $share >= 50,
                    'starts_on' => $this->today->subMonths(18)->toDateString(),
                ]);

                ManagementAgreement::query()->create([
                    'organization_id' => $this->organization->getKey(),
                    'owner_id' => $owner->getKey(),
                    'property_id' => $this->properties[$propertyKey]->getKey(),
                    'name' => 'Full management',
                    'reference' => 'MA-'.strtoupper(substr($key, 0, 3)).'-'.strtoupper(substr($propertyKey, 0, 3)),
                    'commission_model' => ManagementAgreement::PERCENT_OF_REVENUE,
                    'commission_rate' => $definition['commission'],
                    'currency' => 'EUR',
                    'commission_on_accommodation' => true,
                    'commission_on_fees' => false,
                    'commission_on_taxes' => false,
                    'deduct_channel_commission_first' => true,
                    'owner_pays_cleaning' => false,
                    'owner_pays_maintenance' => true,
                    'maintenance_markup_percent' => 10,
                    'maintenance_approval_threshold' => 25000,
                    'owner_stay_nights_included' => 14,
                    'starts_on' => $this->today->subMonths(18)->toDateString(),
                    'notice_period_days' => 90,
                    'status' => 'active',
                ]);
            }

            $this->owners[$key] = $owner->fresh();
        }
    }

    /**
     * Distribution.
     *
     * Both accounts are connected to simulated adapters, and the platform says
     * so everywhere it reports on them. No partner agreement exists for either
     * channel, so nothing here reaches Airbnb or Booking.com — and a demo that
     * implied otherwise would be teaching the one lesson this product cannot
     * afford to teach.
     */
    private function seedChannels(): void
    {
        $organization = $this->organization->getKey();

        $airbnb = ChannelAccount::query()->create([
            'organization_id' => $organization,
            'channel' => 'airbnb',
            'name' => 'Airbnb — Demo Hospitality',
            'status' => ChannelAccount::STATUS_CONNECTED,
            'sync_availability' => true,
            'sync_rates' => true,
            'import_reservations' => true,
            'sync_messages' => true,
            'commission_basis_points' => 300,
            // Airbnb takes the guest's money and remits later, so a booking
            // from here is a receivable rather than cash in our bank.
            'collects_payment' => true,
            'connected_at' => $this->today->subMonths(12),
        ]);

        $booking = ChannelAccount::query()->create([
            'organization_id' => $organization,
            'channel' => 'booking_com',
            'name' => 'Booking.com — Demo Hospitality',
            'status' => ChannelAccount::STATUS_CONNECTED,
            'sync_availability' => true,
            'sync_rates' => true,
            'import_reservations' => true,
            'commission_basis_points' => 1500,
            // Booking.com's usual model: the guest pays us directly and the
            // channel invoices its commission afterwards.
            'collects_payment' => false,
            'connected_at' => $this->today->subMonths(9),
        ]);

        foreach (['alfama', 'principe', 'baixa', 'estoril'] as $index => $key) {
            ChannelListing::query()->create([
                'organization_id' => $organization,
                'channel_account_id' => $airbnb->getKey(),
                'listing_id' => $this->listings[$key]->getKey(),
                'property_id' => $this->properties[$key]->getKey(),
                'external_listing_id' => 'ABB-'.(1000 + $index),
                'external_name' => $this->properties[$key]->name,
                'status' => 'listed',
                'is_active' => true,
            ]);
        }

        foreach (['alfama', 'baixa'] as $index => $key) {
            ChannelListing::query()->create([
                'organization_id' => $organization,
                'channel_account_id' => $booking->getKey(),
                'listing_id' => $this->listings[$key]->getKey(),
                'property_id' => $this->properties[$key]->getKey(),
                'external_listing_id' => 'BDC-'.(2000 + $index),
                'external_name' => $this->properties[$key]->name,
                'status' => 'listed',
                'is_active' => true,
            ]);
        }
    }

    /**
     * Guests, and a book of business around today.
     *
     * Each booking goes through the reservation service, so availability is
     * really checked and the rate is really calculated from the rules above.
     * The stays are laid out so that on any day this runs there are guests in
     * house, arrivals imminent, departures due and a month of history behind.
     */
    private function seedGuestsAndBookings(): void
    {
        $guests = app(GuestDirectory::class);

        $people = [
            ['Marta', 'Silva', 'marta.silva@guests.test', 'PT', '+351 913 000 001'],
            ['James', 'Whitfield', 'james.whitfield@guests.test', 'GB', '+44 7700 900101'],
            ['Sofia', 'Almeida', 'sofia.almeida@guests.test', 'BR', '+55 11 90000 0001'],
            ['Klaus', 'Bauer', 'klaus.bauer@guests.test', 'DE', '+49 151 00000001'],
            ['Amelie', 'Rousseau', 'amelie.rousseau@guests.test', 'FR', '+33 6 00 00 00 01'],
            ['Yuki', 'Tanaka', 'yuki.tanaka@guests.test', 'JP', '+81 90 0000 0001'],
            ['Daniel', 'Okonkwo', 'daniel.okonkwo@guests.test', 'NG', '+234 800 000 0001'],
            ['Elena', 'Petrova', 'elena.petrova@guests.test', 'BG', '+359 88 000 0001'],
        ];

        $directory = [];

        foreach ($people as [$first, $last, $email, $country, $phone]) {
            $directory[] = $guests->findOrCreate([
                'first_name' => $first,
                'last_name' => $last,
                'email' => $email,
                'phone' => $phone,
                'country_code' => $country,
                'language' => 'en',
            ]);
        }

        // [property, guest index, nights from today to check in, nights,
        //  adults, source, status]
        $plan = [
            // In house today.
            ['alfama', 0, -2, 5, 2, 'airbnb', ReservationStatus::CheckedIn],
            ['estoril', 3, -1, 6, 4, 'direct', ReservationStatus::CheckedIn],

            // Arriving in the next few days.
            ['principe', 1, 1, 3, 2, 'booking_com', ReservationStatus::Confirmed],
            ['baixa', 2, 2, 4, 3, 'airbnb', ReservationStatus::Confirmed],
            ['alfama', 4, 4, 7, 2, 'direct', ReservationStatus::Confirmed],

            // Further out.
            ['principe', 5, 12, 4, 2, 'airbnb', ReservationStatus::Confirmed],
            ['estoril', 6, 18, 5, 5, 'booking_com', ReservationStatus::Confirmed],
            ['baixa', 7, 25, 3, 2, 'direct', ReservationStatus::Confirmed],

            // History, so the reports and statements have something to say.
            ['alfama', 1, -34, 4, 2, 'airbnb', ReservationStatus::CheckedOut],
            ['baixa', 3, -30, 6, 4, 'booking_com', ReservationStatus::CheckedOut],
            ['principe', 2, -24, 3, 2, 'direct', ReservationStatus::CheckedOut],
            ['estoril', 5, -20, 7, 6, 'airbnb', ReservationStatus::CheckedOut],
            ['alfama', 6, -14, 3, 2, 'direct', ReservationStatus::CheckedOut],
            ['baixa', 4, -9, 4, 2, 'airbnb', ReservationStatus::CheckedOut],
        ];

        $service = app(ReservationService::class);

        foreach ($plan as [$propertyKey, $guestIndex, $offset, $nights, $adults, $source, $status]) {
            $reservation = $this->book(
                $service,
                $propertyKey,
                $directory[$guestIndex],
                $offset,
                $nights,
                $adults,
                $source,
                $status,
            );

            if ($reservation !== null) {
                $this->reservations[] = $reservation;
            }
        }

        $this->seedCancellation($service, $directory[7]);
        $this->seedPayments();
    }

    /**
     * One booking, taken exactly as the application would take it.
     *
     * A collision is reported rather than worked around: if the availability
     * engine refuses one of these, the plan above is wrong and silently
     * skipping it would hide that.
     */
    private function book(
        ReservationService $service,
        string $propertyKey,
        Guest $guest,
        int $offset,
        int $nights,
        int $adults,
        string $source,
        ReservationStatus $status,
    ): ?Reservation {
        try {
            return $service->create(new ReservationRequest(
                listing: $this->listings[$propertyKey],
                checkIn: $this->today->addDays($offset),
                checkOut: $this->today->addDays($offset + $nights),
                adults: $adults,
                status: $status,
                source: $source,
                guest: $guest,
                bookedAt: $this->today->addDays($offset)->subDays(random_int(5, 60)),
                // Historic stays are entered after the fact, exactly as an
                // agent records a booking that reached the business by some
                // other route. The stay restrictions are waived for those and
                // for nothing else: a future demo booking is checked in full.
                overrideRestrictions: $offset < 0,
            ));
        } catch (Throwable $exception) {
            $this->command?->warn(sprintf(
                'Skipped a demo booking for %s (%+d days, %d nights): %s',
                $propertyKey,
                $offset,
                $nights,
                $exception->getMessage(),
            ));

            return null;
        }
    }

    /**
     * A cancellation, so the refund path and the cancelled state are visible.
     */
    private function seedCancellation(ReservationService $service, Guest $guest): void
    {
        $reservation = $this->book(
            $service,
            'principe',
            $guest,
            40,
            3,
            2,
            'direct',
            ReservationStatus::Confirmed,
        );

        if ($reservation === null) {
            return;
        }

        $service->cancel(
            $reservation,
            reason: 'Guest cancelled — change of plans.',
            cancelledBy: 'guest',
        );
    }

    /**
     * Money against the bookings.
     *
     * Every payment here is marked simulated by the payment service, because
     * no payment provider is configured. That flag travels all the way to the
     * screen: a demo that showed these as settled card payments would be
     * claiming a capability the platform does not have.
     */
    private function seedPayments(): void
    {
        $payments = app(PaymentService::class);

        foreach ($this->reservations as $reservation) {
            $due = $reservation->grandTotal();

            if ($due->minorUnits <= 0) {
                continue;
            }

            $collectedByChannel = $reservation->source === 'airbnb';

            try {
                if ($collectedByChannel) {
                    // Airbnb holds the guest's money and remits later. Recording
                    // it as an external payment is the truthful shape: the
                    // booking is paid, and the cash is not ours yet.
                    $payments->recordExternalPayment($due, $reservation, [
                        'method' => 'channel',
                        'provider_reference' => 'AIRBNB-'.$reservation->confirmation_code,
                        'description' => 'Collected by the channel',
                        // The fact the ledger turns on: this is a receivable
                        // from Airbnb, not cash in our account.
                        'is_collected_by_us' => false,
                    ]);

                    continue;
                }

                // Everything else: a deposit now, the balance charged later.
                $deposit = Money::of((int) round($due->minorUnits * 0.3), $due->currency);

                $payments->charge($deposit, $reservation, ['description' => 'Deposit']);

                if ($reservation->check_in_date->isPast()) {
                    $payments->charge(
                        $due->subtract($deposit),
                        $reservation,
                        ['description' => 'Balance'],
                    );
                }
            } catch (Throwable $exception) {
                $this->command?->warn(sprintf(
                    'Could not record a demo payment for %s: %s',
                    $reservation->confirmation_code,
                    $exception->getMessage(),
                ));
            }
        }
    }

    /**
     * The work that follows from the bookings.
     *
     * Turnovers are scheduled by a listener when a booking is confirmed, so
     * they already exist. What is added here is the rest of a real week: a
     * maintenance ticket somebody raised, an inspection due, and a job that
     * has already been done.
     */
    private function seedOperations(): void
    {
        $tasks = app(TaskService::class);
        $template = ChecklistTemplate::query()->where('kind', 'cleaning')->first();

        $maintenance = $tasks->create([
            'property_id' => $this->properties['baixa']->getKey(),
            'kind' => TaskKind::Maintenance,
            'priority' => TaskPriority::High,
            'title' => 'Bathroom extractor fan not running',
            'description' => 'Reported by the guest in 2B. The fan hums but the blades do not turn.',
            'scheduled_start' => $this->today->addDay()->setTime(9, 0),
            'due_at' => $this->today->addDay()->setTime(13, 0),
            'estimated_minutes' => 90,
        ]);

        $tasks->assign($maintenance, $this->staff['maintenance']->getKey());

        $inspection = $tasks->create([
            'property_id' => $this->properties['estoril']->getKey(),
            'kind' => TaskKind::Inspection,
            'priority' => TaskPriority::Normal,
            'title' => 'Quarterly inspection',
            'scheduled_start' => $this->today->addDays(3)->setTime(10, 0),
            'due_at' => $this->today->addDays(3)->setTime(18, 0),
            'estimated_minutes' => 60,
        ], ChecklistTemplate::query()->where('kind', 'inspection')->first());

        $tasks->assign($inspection, $this->staff['operations']->getKey());

        $urgent = $tasks->create([
            'property_id' => $this->properties['alfama']->getKey(),
            'kind' => TaskKind::Maintenance,
            'priority' => TaskPriority::Urgent,
            'title' => 'No hot water',
            'description' => 'Guest in house. Boiler shows an error code.',
            // Deliberately in the past, so the board opens with something
            // genuinely overdue rather than a tidy empty column.
            'scheduled_start' => $this->today->subDay()->setTime(16, 0),
            'due_at' => $this->today->subDay()->setTime(20, 0),
            'estimated_minutes' => 120,
        ]);

        $tasks->assign($urgent, $this->staff['maintenance']->getKey());

        $done = $tasks->create([
            'property_id' => $this->properties['principe']->getKey(),
            'kind' => TaskKind::Cleaning,
            'priority' => TaskPriority::Normal,
            'title' => 'Turnover clean',
            'scheduled_start' => $this->today->subDays(2)->setTime(11, 0),
            'due_at' => $this->today->subDays(2)->setTime(15, 0),
            'estimated_minutes' => 150,
        ], $template);

        $tasks->assign($done, $this->staff['cleaner']->getKey());

        try {
            // Completed with the checklist forced, because the demo has no
            // photographs to attach and the completion rules are real.
            $tasks->complete($done, ['notes' => 'All rooms finished, no damage.'], force: true);
        } catch (Throwable $exception) {
            $this->command?->warn('Could not complete the demo clean: '.$exception->getMessage());
        }
    }

    /**
     * A door lock on one property.
     *
     * Marked simulated, and it really is: the only lock provider in the
     * platform is a local mock, so a code issued here opens nothing. The flag
     * is on the record itself, not merely in a comment, because
     * {@see AccessCodeManager} refuses to call a
     * code active unless a provider accepted it — and a demo lock that
     * appeared to open a real door would be the single most dangerous
     * untruth this product could tell.
     */
    private function seedLocks(): void
    {
        // The simulated vendor's own inventory. Without this row the provider
        // rejects every code with "this lock is not known", which is the right
        // answer: our record of a lock is not the same thing as the lock.
        DB::table('simulated_locks')->insert([
            'id' => (string) Str::ulid(),
            'connection_id' => 'demo-connection',
            'external_lock_id' => 'MOCK-LOCK-0001',
            'name' => 'Front door',
            'model' => 'Simulated keypad',
            'online' => true,
            'locked' => true,
            'battery_percent' => 84,
            'last_seen_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        SmartLock::query()->create([
            'organization_id' => $this->organization->getKey(),
            'property_id' => $this->properties['alfama']->getKey(),
            'name' => 'Front door',
            'provider' => 'mock',
            'connection_id' => 'demo-connection',
            'external_lock_id' => 'MOCK-LOCK-0001',
            'location' => 'Main entrance, street level',
            'status' => SmartLock::ACTIVE,
            'is_simulated' => true,
        ]);
    }

    /**
     * Door codes for the stays at the property that has a lock.
     *
     * Issued through the manager, so a code only reaches the active state if
     * the provider accepted it. The mock provider does accept, and the code
     * carries `opens_a_real_door = false` all the way to the screen.
     */
    private function issueAccessCodes(): void
    {
        $manager = app(AccessCodeManager::class);
        $lockedProperty = $this->properties['alfama']->getKey();

        foreach ($this->reservations as $reservation) {
            if ($reservation->property_id !== $lockedProperty) {
                continue;
            }

            if ($reservation->check_out_date->isPast()) {
                continue;
            }

            try {
                $manager->issueForReservation($reservation);
            } catch (Throwable $exception) {
                $this->command?->warn(
                    'Could not issue a demo access code: '.$exception->getMessage(),
                );
            }
        }
    }

    /**
     * The inbox, with something actually waiting for a reply.
     */
    private function seedConversations(): void
    {
        $service = app(ConversationService::class);

        $inHouse = collect($this->reservations)
            ->first(fn (Reservation $reservation): bool => $reservation->status === ReservationStatus::CheckedIn);

        $arriving = collect($this->reservations)
            ->first(fn (Reservation $reservation): bool => $reservation->check_in_date->isFuture());

        if ($inHouse !== null) {
            $conversation = $service->forReservation($inHouse);

            $service->recordInbound($conversation, [
                'body' => 'Hi — we have arrived and settled in. Could you tell us how the induction hob works? '
                    .'There are no markings on the dial.',
                'channel' => 'airbnb',
                'sent_at' => now()->subHours(5),
            ]);

            $service->send($conversation, [
                'body' => 'Welcome! Hold the power symbol for two seconds, then use the + and − keys. '
                    .'There is a photo of the controls in the house manual on the shelf by the door.',
            ]);

            $service->recordInbound($conversation, [
                'body' => 'That worked, thank you. One more thing — is there a late check-out on Sunday?',
                'channel' => 'airbnb',
                'sent_at' => now()->subHours(2),
            ]);

            // Left unanswered on purpose: an inbox whose every thread is
            // closed demonstrates nothing about the queue it exists to manage.
        }

        if ($arriving !== null) {
            $conversation = $service->forReservation($arriving);

            $service->recordInbound($conversation, [
                'body' => 'Looking forward to the stay. We land at 22:40 — is a late arrival a problem?',
                'channel' => $arriving->source,
                'sent_at' => now()->subDay(),
            ]);

            $service->send($conversation, [
                'body' => 'Not at all — the lockbox works at any hour and we will send the code tomorrow morning.',
            ]);

            $service->addNote(
                $conversation,
                'Late arrival: tell the cleaner the flat must be ready by 18:00, not 20:00.',
            );
        }
    }

    /**
     * Costs, including one an owner will be charged for.
     */
    private function seedCosts(): void
    {
        $expenses = app(ExpenseService::class);

        $repair = $expenses->create([
            'property_id' => $this->properties['baixa']->getKey(),
            'expense_date' => $this->today->subDays(12)->toDateString(),
            'category' => 'maintenance',
            'description' => 'Replacement extractor fan and fitting',
            'amount' => 18500,
            'currency' => 'EUR',
            'markup_percent' => 10,
            'billable_to' => 'owner',
            'notes' => 'Tejo Plumbing, callout plus parts.',
        ]);

        $expenses->approve($repair);

        $supplies = $expenses->create([
            'property_id' => $this->properties['alfama']->getKey(),
            'expense_date' => $this->today->subDays(6)->toDateString(),
            'category' => 'supplies',
            'description' => 'Consumables restock: coffee, toiletries, cleaning products',
            'amount' => 4200,
            'currency' => 'EUR',
            'billable_to' => 'management',
        ]);

        $expenses->approve($supplies);

        // Left as a draft, so the approval queue is not empty and the rule
        // that a draft posts nothing to the ledger can be seen to hold.
        $expenses->create([
            'property_id' => $this->properties['estoril']->getKey(),
            'expense_date' => $this->today->subDays(2)->toDateString(),
            'category' => 'gardening',
            'description' => 'Hedge cutting and lawn treatment',
            'amount' => 9000,
            'currency' => 'EUR',
            'billable_to' => 'owner',
            'notes' => 'Awaiting the contractor\'s invoice before approval.',
        ]);
    }

    /**
     * Reviews on two scales, because that is the problem reviews actually pose.
     */
    private function seedReviews(): void
    {
        $service = app(ReviewService::class);

        $past = collect($this->reservations)
            ->filter(fn (Reservation $r): bool => $r->status === ReservationStatus::CheckedOut)
            ->values();

        $definitions = [
            ['airbnb', 5, 5, 'Beautiful apartment, spotless', 'Exactly as described and immaculate on arrival. The terrace is the best part.', true],
            ['booking_com', 9, 10, 'Great location', 'Very central and quiet at night. The shower was slow to heat up.', true],
            ['airbnb', 3, 5, 'Fine, but the cleaning let it down', 'Good space and a great spot, but the kitchen had not been properly cleaned before we arrived.', false],
            ['booking_com', 10, 10, 'Faultless', 'Communication was quick and the check-in could not have been easier.', false],
        ];

        foreach ($definitions as $index => [$source, $rating, $scale, $title, $comment, $answered]) {
            $reservation = $past[$index] ?? null;

            $review = $service->import([
                'direction' => 'guest_to_host',
                'source' => $source,
                'external_id' => strtoupper($source).'-REV-'.(100 + $index),
                'rating' => $rating,
                // The scale travels with the score. Nine out of ten and nine
                // out of five are not the same review, and an average that
                // forgets which is which is worthless.
                'rating_scale' => $scale,
                'title' => $title,
                'public_comment' => $comment,
                'property_id' => $reservation?->property_id ?? $this->properties['alfama']->getKey(),
                'reservation' => $reservation,
                'submitted_at' => $this->today->subDays(30 - ($index * 6)),
                'status' => Review::PUBLISHED,
            ]);

            if ($answered) {
                $service->respond(
                    $review,
                    'Thank you for taking the time to write — we are glad the stay worked well, '
                    .'and we have passed your note to the housekeeping team.',
                );
            }
        }
    }

    /**
     * Last month's statements, and a payout against one of them.
     */
    private function seedStatements(): void
    {
        $builder = app(OwnerStatementBuilder::class);
        $payouts = app(OwnerPayoutService::class);

        $from = $this->today->subMonthNoOverflow()->startOfMonth();
        $to = $from->endOfMonth();

        foreach ($this->owners as $key => $owner) {
            try {
                $statement = $builder->build($owner, $from, $to);
            } catch (Throwable $exception) {
                $this->command?->warn(sprintf(
                    'Could not build a statement for %s: %s',
                    $owner->display_name,
                    $exception->getMessage(),
                ));

                continue;
            }

            // One owner's statement is left in draft on purpose: approving
            // every one of them would hide the freeze that approval applies.
            if ($key === 'ferreira') {
                continue;
            }

            $builder->approve($statement->fresh());

            if ($key === 'okafor') {
                $payout = $payouts->fromStatement($statement->fresh());

                $payouts->markPaid($payout, 'SEPA-'.$this->today->format('Ym').'-0001');
            }
        }
    }

    /**
     * Put the demo organization on a plan.
     *
     * Professional rather than Portfolio, on purpose: it includes the features
     * the demo actually uses and omits a few, so the plan gate can be seen doing
     * something rather than being invisible because everything is included.
     */
    private function assignPlan(): void
    {
        $plan = Plan::query()->where('slug', 'professional')->first();

        if ($plan === null || $this->operator === null) {
            return;
        }

        app(TenantAdministration::class)->changePlan(
            $this->organization,
            $plan,
            $this->operator,
            'Demo portfolio set up on the Professional plan.',
        );
    }

    /**
     * Run the queued work the seeding produced.
     *
     * Turnover cleans, door codes and notifications are queued listeners, so
     * without a worker the demo would open on an empty operations board while
     * a dozen jobs sat unrun. Draining the queue here produces the state a
     * deployed system reaches a second later, rather than reproducing that
     * work inline and having two code paths that can disagree.
     */
    private function drainTheQueue(): void
    {
        try {
            Artisan::call('queue:work', [
                '--stop-when-empty' => true,
                '--tries' => 1,
                '--quiet' => true,
            ]);
        } catch (Throwable $exception) {
            $this->command?->warn(
                'The queue could not be drained ('.$exception->getMessage().'). '
                .'Start a worker to generate the turnover cleans.',
            );
        }
    }

    private function report(): void
    {
        $this->command?->info('Demo Hospitality Group is ready.');
        $this->command?->line('  Administrator:  admin@demo-hospitality.test');
        $this->command?->line('  Manager:        manager@demo-hospitality.test');
        $this->command?->line('  Operations:     operations@demo-hospitality.test');
        $this->command?->line('  Reservations:   reservations@demo-hospitality.test');
        $this->command?->line('  Accounts:       accounts@demo-hospitality.test');
        $this->command?->line('  Housekeeping:   cleaning@demo-hospitality.test');
        $this->command?->line('  Maintenance:    maintenance@demo-hospitality.test');
        $this->command?->line('  Password:       '.self::PASSWORD);
        $this->command?->newLine();
        $this->command?->line('  Platform console (governs every tenant):');
        $this->command?->line('    platform@habitat.test — same password, no membership anywhere.');
        $this->command?->newLine();
        $this->command?->warn(
            'Both channel connections run against local simulations. Nothing in this '
            .'organization reaches Airbnb or Booking.com, and the interface says so.',
        );
    }
}
