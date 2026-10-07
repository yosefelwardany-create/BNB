<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Accounting\Services\ChartOfAccountsInstaller;
use App\Domain\Agents\Services\AgentBriefStore;
use App\Domain\Availability\Models\CalendarBlock;
use App\Domain\Guests\Models\Guest;
use App\Domain\Guests\Services\GuestDirectory;
use App\Domain\Listings\Models\Listing;
use App\Domain\Listings\Services\ListingService;
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
use App\Domain\Owners\Models\Owner;
use App\Domain\Owners\Services\ClientAccounts;
use App\Domain\Owners\Services\OwnershipLedger;
use App\Domain\Payments\Services\ExpenseService;
use App\Domain\Payments\Services\PaymentService;
use App\Domain\Pricing\Models\PricingRule;
use App\Domain\Pricing\Models\RatePlan;
use App\Domain\Properties\Models\Amenity;
use App\Domain\Properties\Models\Portfolio;
use App\Domain\Properties\Models\Property;
use App\Domain\Properties\Services\CancellationPolicyInstaller;
use App\Domain\Properties\Services\PropertyService;
use App\Domain\Reservations\DataObjects\ReservationRequest;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Reservations\Services\ReservationService;
use App\Domain\Reviews\Models\Review;
use App\Domain\Reviews\Services\ReviewService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * A sample client account: Juan Lopez, five properties in Bogotá.
 *
 * Built for a live platform, so it is careful about what leaves the database:
 *
 *  - No login. The account holder carries a placeholder address; the platform
 *    owner replaces it on Owners and grants portal access when ready.
 *  - No channel connection, so nothing is pushed to Airbnb or Hostex and no
 *    scheduled pull runs against this account.
 *  - Guest messages are recorded with automatic replies off, and our replies
 *    are recorded as already delivered: no email, no AI call, no channel send.
 *  - Payments are recorded as collected by Airbnb, so no payment provider is
 *    called.
 *  - Guests use example.com addresses, a domain reserved so mail never arrives.
 *
 * Bookings still go through the reservation service, so availability, rates,
 * turnovers and the client's figures are computed exactly as for real ones.
 * Dates are relative to the day it runs. It runs once: a second run finds the
 * account and stops.
 */
class JuanLopezDemoSeeder extends Seeder
{
    public const SLUG = 'juan-lopez';

    private const PLACEHOLDER_EMAIL = 'juan.lopez@example.com';

    private const CURRENCY = 'USD';

    private const TIMEZONE = 'America/Bogota';

    private Organization $organization;

    private CarbonImmutable $today;

    /** @var array<string, Property> */
    private array $properties = [];

    /** @var array<string, Listing> */
    private array $listings = [];

    /** @var array<string, list<Reservation>> */
    private array $bookings = [];

    private ?Owner $holder = null;

    public function run(): void
    {
        $tenancy = app(TenantContext::class);

        $existing = $tenancy->withoutScope(
            fn () => Organization::query()->where('slug', self::SLUG)->first(),
        );

        if ($existing !== null) {
            $this->command?->warn('The Juan Lopez sample account already exists. Its data was left as it is.');

            // Photos came later than the account; a property still without any
            // receives the sample set, and one with photos is left alone.
            $this->call(JuanLopezSamplePhotosSeeder::class);

            return;
        }

        $this->today = CarbonImmutable::today(self::TIMEZONE);

        // All or nothing: a deploy cut short leaves no half-built account, and
        // the next run starts clean. Run it with the sync queue so the listeners
        // it triggers (turnover cleans) run inside the same transaction.
        DB::transaction(fn () => $this->build($tenancy));

        $this->command?->info(sprintf(
            'Juan Lopez sample account created: %d properties, %d bookings.',
            count($this->properties),
            array_sum(array_map('count', $this->bookings)),
        ));

        $this->call(JuanLopezSamplePhotosSeeder::class);
    }

    private function build(TenantContext $tenancy): void
    {
        $this->organization = app(OrganizationProvisioner::class)->createOrganization([
            'name' => 'Juan Lopez',
            'legal_name' => 'Juan Lopez',
            'slug' => self::SLUG,
            'status' => 'active',
            'trial_ends_at' => null,
            'base_currency' => self::CURRENCY,
            'timezone' => self::TIMEZONE,
            'locale' => 'es',
            'country_code' => 'CO',
            'contact_email' => self::PLACEHOLDER_EMAIL,
            'contact_phone' => '+57 300 000 0000',
        ]);

        $tenancy->runAs($this->organization, function (): void {
            app(ChartOfAccountsInstaller::class)->install($this->organization);
            app(CancellationPolicyInstaller::class)->install($this->organization);

            $this->seedCatalogue();
            $this->seedProperties();
            $this->seedAccountHolder();
            $this->seedBookings();
            $this->seedCalendarBlocks();
            $this->seedConversations();
            $this->seedOperations();
            $this->seedExpenses();
            $this->seedReviews();
            $this->seedStatements();
        });
    }

    private function seedCatalogue(): void
    {
        $organization = $this->organization->getKey();

        RatePlan::query()->create([
            'organization_id' => $organization,
            'name' => 'Standard',
            'slug' => 'standard',
            'description' => 'The published nightly rate, flexible cancellation.',
            'currency' => self::CURRENCY,
            'derivation_type' => 'independent',
            'is_default' => true,
            'is_active' => true,
            'priority' => 100,
        ]);

        PricingRule::query()->create([
            'organization_id' => $organization,
            'name' => 'Weekend uplift',
            'description' => 'Friday and Saturday nights.',
            'kind' => 'day_of_week',
            'days_of_week' => [5, 6],
            'adjustment_type' => 'percentage',
            'adjustment_value' => 15,
            'priority' => 10,
            'is_active' => true,
        ]);

        PricingRule::query()->create([
            'organization_id' => $organization,
            'name' => 'December holidays',
            'description' => 'Christmas and New Year in Bogotá.',
            'kind' => 'seasonal',
            'stay_from' => $this->today->setMonth(12)->setDay(15)->toDateString(),
            'stay_to' => $this->today->setMonth(12)->setDay(31)->toDateString(),
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
            'adjustment_value' => -10,
            'floor_rate' => 4000,
            'priority' => 30,
            'is_active' => true,
        ]);

        ChecklistTemplate::query()->create([
            'organization_id' => $organization,
            'name' => 'Turnover clean',
            'kind' => 'cleaning',
            'description' => 'Between one guest leaving and the next arriving.',
            'items' => [
                ['label' => 'Strip and remake all beds', 'requires_photo' => false],
                ['label' => 'Bathrooms: clean, restock, fresh towels', 'requires_photo' => false],
                ['label' => 'Kitchen: empty fridge, wash dishes, wipe surfaces', 'requires_photo' => false],
                ['label' => 'Check for damage and items left behind', 'requires_photo' => false],
                ['label' => 'Photograph each finished room', 'requires_photo' => true],
                ['label' => 'Test the digital lock and replace batteries below 30%', 'requires_photo' => false],
            ],
            'is_default' => true,
            'is_active' => true,
        ]);

        ChecklistTemplate::query()->create([
            'organization_id' => $organization,
            'name' => 'Camera check',
            'kind' => 'inspection',
            'description' => 'Every camera online, recording and pointed where it should be.',
            'items' => [
                ['label' => 'Each camera shows live video in the app', 'requires_photo' => false],
                ['label' => 'Last 24 hours of recordings play back', 'requires_photo' => false],
                ['label' => 'Lens clean and angle unchanged', 'requires_photo' => true],
                ['label' => 'No camera covers a bedroom or bathroom', 'requires_photo' => false],
            ],
            'is_active' => true,
        ]);

        Vendor::query()->create([
            'organization_id' => $organization,
            'name' => 'Limpieza Andina',
            'category' => 'cleaning',
            'contact_name' => 'Marcela Ríos',
            'email' => 'contacto@limpieza-andina.example.com',
            'phone' => '+57 300 111 1111',
            'city' => 'Bogotá',
            'country_code' => 'CO',
            'hourly_rate' => 800,
            'currency' => self::CURRENCY,
            'is_active' => true,
        ]);

        Vendor::query()->create([
            'organization_id' => $organization,
            'name' => 'SegurCam Bogotá',
            'category' => 'security',
            'contact_name' => 'Andrés Gómez',
            'email' => 'soporte@segurcam.example.com',
            'phone' => '+57 300 222 2222',
            'city' => 'Bogotá',
            'country_code' => 'CO',
            'callout_fee' => 2500,
            'currency' => self::CURRENCY,
            'is_active' => true,
        ]);

        Vendor::query()->create([
            'organization_id' => $organization,
            'name' => 'Cerrajería El Lago',
            'category' => 'locksmith',
            'contact_name' => 'Jorge Peña',
            'email' => 'servicio@cerrajeria-ellago.example.com',
            'phone' => '+57 300 333 3333',
            'city' => 'Bogotá',
            'country_code' => 'CO',
            'callout_fee' => 2000,
            'currency' => self::CURRENCY,
            'is_active' => true,
        ]);

        MessageTemplate::query()->create([
            'organization_id' => $organization,
            'name' => 'Arrival details',
            'code' => 'arrival-details',
            'category' => 'pre_arrival',
            'subject' => 'Getting into {{ property.name }}',
            'body' => "Hola {{ guest.first_name }},\n\n"
                ."You arrive tomorrow. Check-in is from {{ property.check_in_time }}.\n\n"
                ."{{ property.check_in_instructions }}\n\n"
                .'¡Buen viaje!',
            'language' => 'en',
            'transport' => 'email',
            'is_active' => true,
        ]);

        MessageTemplate::query()->create([
            'organization_id' => $organization,
            'name' => 'Recordatorio de salida',
            'code' => 'checkout-reminder',
            'category' => 'pre_departure',
            'subject' => 'Salida de {{ property.name }} mañana',
            'body' => "Hola {{ guest.first_name }},\n\n"
                ."Te recordamos que la salida es mañana antes de las {{ property.check_out_time }}.\n\n"
                ."{{ property.check_out_instructions }}\n\n"
                .'¡Gracias por tu estadía!',
            'language' => 'es',
            'transport' => 'email',
            'is_active' => true,
        ]);
    }

    private function seedProperties(): void
    {
        $organization = $this->organization->getKey();
        $properties = app(PropertyService::class);
        $listings = app(ListingService::class);
        $briefs = app(AgentBriefStore::class);

        $portfolio = Portfolio::query()->create([
            'organization_id' => $organization,
            'name' => 'Bogotá',
            'slug' => 'bogota',
            'description' => 'Juan Lopez\'s properties in Bogotá.',
            'color' => '#16a34a',
            'is_active' => true,
        ]);

        $standardRules = "No parties or events.\nNo smoking indoors.\nQuiet hours 22:00–07:00.\n"
            .'Only the guests on the reservation may stay or visit. The entrance is recorded by a security camera.';

        $definitions = [
            'carrera7' => [
                'name' => 'Apto Carrera 7',
                'reference' => 'JL-001',
                'type' => 'apartment',
                'address' => ['Carrera 7 # 45-12', 'Apto 502', '110231'],
                'neighbourhood' => 'Chapinero',
                'coordinates' => [4.6337, -74.0661],
                'bedrooms' => 1, 'bathrooms' => 1, 'beds' => 1, 'occupancy' => 2, 'size' => 48, 'floor' => '5',
                'rate' => 5500, 'cleaning' => 1500, 'deposit' => 10000,
                'summary' => 'A bright one-bedroom apartment on Carrera Séptima, steps from Chapinero\'s cafés.',
                'space' => 'Open living room with a sofa bed, a fully equipped kitchenette, one bedroom with a queen bed and a work desk by the window.',
                'cameras' => [1, 'Tapo', 'Guest entry and exit; guest count against the reservation; extra or unauthorized people; parties and noise; objects being removed; tampering with the digital lock.'],
                'secrets' => ['door_code' => '482917', 'wifi_network' => 'Carrera7-502', 'wifi_password' => 'septima-cafe-2024'],
                'method' => 'smart_lock',
                'amenities' => ['wifi', 'hot_water', 'kitchenette', 'refrigerator', 'microwave', 'coffee_maker', 'workspace', 'smart_tv', 'elevator', 'smoke_alarm', 'security_cameras_exterior', 'essentials'],
                'bot' => 'Valentina',
            ],
            'casa62' => [
                'name' => 'Casa 62',
                'reference' => 'JL-002',
                'type' => 'house',
                'address' => ['Calle 62 # 9-35', null, '110231'],
                'neighbourhood' => 'Chapinero Alto',
                'coordinates' => [4.6469, -74.0617],
                'bedrooms' => 3, 'bathrooms' => 2, 'beds' => 4, 'occupancy' => 6, 'size' => 140, 'floor' => '1–2',
                'rate' => 12000, 'cleaning' => 3500, 'deposit' => 25000,
                'summary' => 'A three-bedroom house on Calle 62 with a patio, close to the Zona G restaurants.',
                'space' => 'Two floors: living and dining room, kitchen and patio downstairs; three bedrooms and two bathrooms upstairs.',
                'cameras' => [1, 'Tapo', 'Count everyone entering and leaving; extra guests; parties or large groups; objects being removed; property damage; people loitering or tampering with locks and access points.'],
                'secrets' => ['door_code' => '730264', 'wifi_network' => 'Casa62', 'wifi_password' => 'patio-zona-g-62'],
                'method' => 'smart_lock',
                'amenities' => ['wifi', 'hot_water', 'kitchen', 'refrigerator', 'oven', 'stove', 'cooking_basics', 'dishes_and_cutlery', 'washing_machine', 'patio', 'smart_tv', 'street_parking', 'smoke_alarm', 'first_aid_kit', 'security_cameras_exterior', 'essentials'],
                'bot' => 'Mateo',
            ],
            'hippies' => [
                'name' => 'Hippies',
                'reference' => 'JL-003',
                'type' => 'loft',
                'address' => ['Calle 60 # 7-21', 'Loft 3', '110231'],
                'neighbourhood' => 'Parque de los Hippies',
                'coordinates' => [4.6455, -74.0640],
                'bedrooms' => 2, 'bathrooms' => 1, 'beds' => 2, 'occupancy' => 4, 'size' => 70, 'floor' => '3',
                'rate' => 7000, 'cleaning' => 2000, 'deposit' => 15000,
                'summary' => 'A two-bedroom loft overlooking Parque de los Hippies, in the heart of Chapinero.',
                'space' => 'Double-height living area with a mezzanine bedroom, a second bedroom, a full kitchen and a small balcony over the park.',
                'cameras' => [1, 'Tapo', 'Guest entry and exit; people matching the reservation; unauthorized visitors; gatherings or excessive noise; objects being removed; unusual behaviour near the entrance.'],
                'secrets' => ['door_code' => '615038', 'wifi_network' => 'Hippies-Loft', 'wifi_password' => 'parque-mezzanine-3'],
                'method' => 'smart_lock',
                'amenities' => ['wifi', 'hot_water', 'kitchen', 'refrigerator', 'stove', 'coffee_maker', 'balcony', 'tv', 'workspace', 'smoke_alarm', 'security_cameras_exterior', 'essentials'],
                'bot' => 'Luna',
            ],
            'pachamama' => [
                'name' => 'Pachamama',
                'reference' => 'JL-004',
                'type' => 'guesthouse',
                'address' => ['Carrera 4 # 69-30', null, '110231'],
                'neighbourhood' => 'Rosales',
                'coordinates' => [4.6566, -74.0520],
                'bedrooms' => 5, 'bathrooms' => 4, 'beds' => 7, 'occupancy' => 12, 'size' => 320, 'floor' => '1–3',
                'rate' => 21000, 'cleaning' => 6000, 'deposit' => 50000,
                'summary' => 'A five-bedroom guesthouse in Rosales with shared common areas, garden and private parking.',
                'space' => 'Five bedrooms over three floors, a large common living room and dining area, a full kitchen, a garden, a storage room and parking for two cars.',
                'cameras' => [10, 'Tapo', 'Access control, guests, common areas, parking, storage areas and operational activity, covered camera by camera.'],
                'secrets' => ['door_code' => '904152', 'wifi_network' => 'Pachamama', 'wifi_password' => 'jardin-rosales-10'],
                'method' => 'smart_lock',
                'amenities' => ['wifi', 'high_speed_internet', 'hot_water', 'kitchen', 'refrigerator', 'freezer', 'oven', 'stove', 'dishwasher', 'cooking_basics', 'dishes_and_cutlery', 'dining_table', 'washing_machine', 'dryer', 'garden', 'outdoor_furniture', 'bbq_grill', 'free_parking', 'smart_tv', 'board_games', 'smoke_alarm', 'fire_extinguisher', 'first_aid_kit', 'security_cameras_exterior', 'essentials'],
                'bot' => 'Inti',
            ],
            'estrella' => [
                'name' => 'Estrella de Oriente',
                'reference' => 'JL-005',
                'type' => 'house',
                'address' => ['Calle 12B # 1-48', null, '111711'],
                'neighbourhood' => 'La Candelaria',
                'coordinates' => [4.5981, -74.0705],
                'bedrooms' => 4, 'bathrooms' => 3, 'beds' => 5, 'occupancy' => 8, 'size' => 210, 'floor' => '1–2',
                'rate' => 16000, 'cleaning' => 4500, 'deposit' => 40000,
                'summary' => 'A restored colonial house in La Candelaria with a central courtyard and views of the eastern hills.',
                'space' => 'Four bedrooms around a central courtyard, a living room with a fireplace, a full kitchen and a roof terrace facing Monserrate.',
                'cameras' => [7, 'DMS', 'Guest entry authorization; confirm an active reservation and the authorized guest count; verify each access checkpoint by camera; do not give access unless the guest is on an active reservation.'],
                'secrets' => ['door_code' => '257381', 'wifi_network' => 'EstrellaOriente', 'wifi_password' => 'monserrate-patio-7'],
                'method' => 'smart_lock',
                'amenities' => ['wifi', 'hot_water', 'heating', 'kitchen', 'refrigerator', 'oven', 'stove', 'cooking_basics', 'dishes_and_cutlery', 'dining_table', 'washing_machine', 'terrace', 'patio', 'smart_tv', 'books', 'smoke_alarm', 'fire_extinguisher', 'first_aid_kit', 'security_cameras_exterior', 'essentials'],
                'bot' => 'Simón',
            ],
        ];

        foreach ($definitions as $key => $definition) {
            [$cameraCount, $cameraApp, $monitor] = $definition['cameras'];

            $amenityIds = Amenity::query()->whereIn('key', $definition['amenities'])->pluck('id')->all();

            $property = $properties->create([
                'portfolio_id' => $portfolio->getKey(),
                'name' => $definition['name'],
                'internal_name' => $definition['name'].' (Juan Lopez)',
                'reference' => $definition['reference'],
                'property_type' => $definition['type'],
                'rental_kind' => 'entire_place',
                'address_line_1' => $definition['address'][0],
                'address_line_2' => $definition['address'][1],
                'city' => 'Bogotá',
                'state' => 'Bogotá D.C.',
                'postal_code' => $definition['address'][2],
                'country_code' => 'CO',
                'neighbourhood' => $definition['neighbourhood'],
                'latitude' => $definition['coordinates'][0],
                'longitude' => $definition['coordinates'][1],
                'timezone' => self::TIMEZONE,
                'currency' => self::CURRENCY,
                'bedrooms' => $definition['bedrooms'],
                'bathrooms' => $definition['bathrooms'],
                'beds' => $definition['beds'],
                'max_occupancy' => $definition['occupancy'],
                'max_adults' => $definition['occupancy'],
                'max_children' => max(0, $definition['occupancy'] - 2),
                'max_infants' => 1,
                'max_pets' => 0,
                'size_value' => $definition['size'],
                'size_unit' => 'sqm',
                'floor' => $definition['floor'],
                'summary' => $definition['summary'],
                'description' => $definition['summary'].' '.$definition['space'],
                'space_description' => $definition['space'],
                'neighbourhood_description' => $definition['neighbourhood'].', Bogotá: restaurants, cafés and shops within walking distance. The area is lively in the evenings and quiet at night.',
                'transit_description' => 'TransMilenio and SITP buses on Carrera Séptima and Avenida Caracas. Taxis and ride-hailing apps are the easiest way to and from El Dorado airport (about 35 minutes).',
                'house_rules' => $standardRules,
                'check_in_instructions' => 'Self check-in with the digital lock. We send your personal door code through Airbnb on the day of arrival. Bring your ID: every guest must match the reservation.',
                'check_out_instructions' => 'Leave the keys inside, close the windows, switch off the lights and make sure the digital lock shows locked when you leave.',
                'internal_notes' => sprintf(
                    "Security cameras: %d (%s app).\nWhat to monitor: %s\nCameras cover entrances and common areas only, never bedrooms or bathrooms.",
                    $cameraCount,
                    $cameraApp,
                    $monitor,
                ),
                'check_in_time' => '15:00',
                'check_out_time' => '11:00',
                'check_in_until' => '23:00',
                'check_in_method' => $definition['method'],
                'access_notes' => 'Digital lock on the main door. A new code is issued for every reservation and expires at check-out.',
                'base_rate' => $definition['rate'],
                'cleaning_fee' => $definition['cleaning'],
                'security_deposit' => $definition['deposit'],
                'extra_guest_fee' => 1000,
                'extra_guest_after' => max(1, $definition['occupancy'] - 2),
                'minimum_nights' => 2,
                'maximum_nights' => 60,
                'cleaning_duration_minutes' => $definition['bedrooms'] >= 4 ? 240 : 150,
                'preparation_hours' => 3,
                'instant_book' => true,
                ...$definition['secrets'],
            ], $amenityIds);

            $properties->activate($property);

            // Backdated so last year's stays count towards occupancy.
            $property->forceFill([
                'activated_at' => $this->today->subMonths(18)->startOfMonth(),
            ])->save();

            $briefs->save($property, [
                'enabled' => true,
                // Drafts only: nothing is ever sent to a guest without a person.
                'automatic_guest_replies' => false,
                'auto_send' => [],
                'bot_name' => $definition['bot'],
                'persona' => 'Warm and brief. Replies in Spanish or English, whichever the guest wrote in.',
                'languages' => ['es', 'en'],
                'escalate' => ['extra guests', 'party', 'noise complaint', 'refund', 'lock problem'],
                'never' => ['share a door code before the day of arrival', 'allow visitors who are not on the reservation', 'offer a discount'],
                'extra_knowledge' => sprintf('The entrance is covered by %d security camera(s). Only guests on the reservation may enter.', $cameraCount),
            ]);

            $listing = $listings->primaryFor($property, [
                'title' => $definition['name'],
                'summary' => $definition['summary'],
                'description' => $definition['summary'].' '.$definition['space'],
                'max_occupancy' => $definition['occupancy'],
                'bedrooms' => $definition['bedrooms'],
                'bathrooms' => $definition['bathrooms'],
                'beds' => $definition['beds'],
                'base_rate' => $definition['rate'],
                'cleaning_fee' => $definition['cleaning'],
                'minimum_nights' => 2,
                'instant_book' => true,
            ], reason: 'Sample account content');

            $this->properties[$key] = $property->fresh();
            $this->listings[$key] = $listing->fresh();
        }
    }

    /**
     * The client: owns every property outright, 10% commission, dated back so
     * the history below is attributed and commissioned.
     */
    private function seedAccountHolder(): void
    {
        $clients = app(ClientAccounts::class);
        $since = $this->today->subMonths(18)->startOfMonth();

        $holder = $clients->ensureAccountHolder($this->organization, [
            'first_name' => 'Juan',
            'last_name' => 'Lopez',
            'email' => self::PLACEHOLDER_EMAIL,
        ]);

        $holder->forceFill([
            'phone' => '+57 300 000 0000',
            'payout_method' => 'bank_transfer',
            'payout_currency' => self::CURRENCY,
            'statement_frequency' => 'monthly',
            'statement_day' => 1,
            'bank_name' => 'Bancolombia',
            'bank_account_name' => 'Juan Lopez',
        ])->save();

        $clients->ensureAgreement($this->organization, $holder, $since);

        foreach ($this->properties as $property) {
            app(OwnershipLedger::class)->assign($property, $holder, [
                'ownership_percentage' => 100,
                'is_primary' => true,
                'starts_on' => $since->toDateString(),
            ]);
        }

        $this->holder = $holder->fresh();
    }

    private function seedBookings(): void
    {
        $directory = app(GuestDirectory::class);

        $people = [
            ['Camila', 'Restrepo', 'CO', '+57 300 000 0101', 'es'],
            ['Santiago', 'Martínez', 'CO', '+57 300 000 0102', 'es'],
            ['Emily', 'Carter', 'US', '+1 202 555 0103', 'en'],
            ['Lucas', 'Fernández', 'AR', '+54 11 5555 0104', 'es'],
            ['Sophie', 'Müller', 'DE', '+49 151 5550 0105', 'en'],
            ['Daniela', 'Vargas', 'MX', '+52 55 5555 0106', 'es'],
            ['Tom', 'Bennett', 'GB', '+44 7700 900106', 'en'],
            ['Valeria', 'Gómez', 'CO', '+57 300 000 0108', 'es'],
            ['Marco', 'Rossi', 'IT', '+39 333 555 0109', 'en'],
            ['Isabela', 'Costa', 'BR', '+55 11 95555 0110', 'en'],
            ['Andrés', 'Herrera', 'CO', '+57 300 000 0111', 'es'],
            ['Claire', 'Dubois', 'FR', '+33 6 55 55 01 12', 'en'],
            ['Nicolás', 'Torres', 'CL', '+56 9 5555 0113', 'es'],
            ['Hannah', 'Kim', 'US', '+1 415 555 0114', 'en'],
        ];

        $guests = [];

        foreach ($people as [$first, $last, $country, $phone, $language]) {
            $guests[] = $directory->findOrCreate([
                'first_name' => $first,
                'last_name' => $last,
                'email' => strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $first.'.'.$last) ?: $first.'.'.$last).'@example.com',
                'phone' => $phone,
                'country_code' => $country,
                'language' => $language,
            ]);
        }

        $out = ReservationStatus::CheckedOut;
        $in = ReservationStatus::CheckedIn;
        $confirmed = ReservationStatus::Confirmed;

        // [property, guest, days from today to check-in, nights, adults, status]
        $plan = [
            ['carrera7', 0, -40, 4, 2, $out], ['carrera7', 2, -25, 3, 1, $out], ['carrera7', 6, -12, 5, 2, $out],
            ['carrera7', 9, -2, 4, 2, $in], ['carrera7', 11, 5, 3, 2, $confirmed], ['carrera7', 13, 15, 6, 1, $confirmed],

            ['casa62', 1, -38, 5, 5, $out], ['casa62', 4, -20, 4, 4, $out], ['casa62', 8, -8, 3, 3, $out],
            ['casa62', 3, -1, 5, 6, $in], ['casa62', 12, 8, 4, 4, $confirmed], ['casa62', 5, 21, 3, 5, $confirmed],

            ['hippies', 7, -35, 3, 2, $out], ['hippies', 10, -27, 6, 3, $out], ['hippies', 2, -10, 4, 2, $out],
            ['hippies', 6, 1, 3, 2, $confirmed], ['hippies', 0, 9, 5, 4, $confirmed], ['hippies', 13, 30, 4, 2, $confirmed],

            ['pachamama', 5, -45, 6, 10, $out], ['pachamama', 11, -30, 5, 8, $out], ['pachamama', 1, -16, 4, 12, $out],
            ['pachamama', 9, -6, 3, 6, $out], ['pachamama', 4, 0, 4, 9, $confirmed], ['pachamama', 8, 14, 7, 10, $confirmed],

            ['estrella', 12, -42, 4, 6, $out], ['estrella', 3, -33, 5, 7, $out], ['estrella', 10, -18, 6, 8, $out],
            ['estrella', 7, -6, 3, 4, $out], ['estrella', 13, 3, 4, 6, $confirmed], ['estrella', 0, 24, 5, 8, $confirmed],
        ];

        $service = app(ReservationService::class);

        foreach ($plan as [$key, $guestIndex, $offset, $nights, $adults, $status]) {
            $reservation = $this->book($service, $key, $guests[$guestIndex], $offset, $nights, $adults, $status);

            if ($reservation !== null) {
                $this->bookings[$key][] = $reservation;
            }
        }

        // A cancellation, so the cancelled state and its zero revenue show.
        $cancelled = $this->book($service, 'hippies', $guests[4], 45, 3, 2, $confirmed);

        if ($cancelled !== null) {
            $service->cancel($cancelled, reason: 'Guest cancelled: change of travel plans.', cancelledBy: 'guest');
        }

        $this->recordPayments();
    }

    private function book(
        ReservationService $service,
        string $key,
        Guest $guest,
        int $offset,
        int $nights,
        int $adults,
        ReservationStatus $status,
    ): ?Reservation {
        try {
            return $service->create(new ReservationRequest(
                listing: $this->listings[$key],
                checkIn: $this->today->addDays($offset),
                checkOut: $this->today->addDays($offset + $nights),
                adults: $adults,
                status: $status,
                source: 'airbnb',
                guest: $guest,
                bookedAt: $this->today->addDays($offset)->subDays(random_int(7, 45)),
                overrideRestrictions: $offset < 0,
            ));
        } catch (Throwable $exception) {
            $this->command?->warn(sprintf('Skipped a sample booking for %s (%+d days): %s', $key, $offset, $exception->getMessage()));

            return null;
        }
    }

    /**
     * Airbnb collects every guest's money, so each booking is paid by the
     * channel: recorded, never charged.
     */
    private function recordPayments(): void
    {
        $payments = app(PaymentService::class);

        foreach ($this->bookings as $reservations) {
            foreach ($reservations as $reservation) {
                $due = $reservation->grandTotal();

                if ($due->minorUnits <= 0) {
                    continue;
                }

                try {
                    $payments->recordExternalPayment($due, $reservation, [
                        'method' => 'channel',
                        'provider_reference' => 'AIRBNB-'.$reservation->confirmation_code,
                        'description' => 'Collected by Airbnb',
                        'is_collected_by_us' => false,
                    ]);
                } catch (Throwable $exception) {
                    $this->command?->warn('Could not record a sample payment: '.$exception->getMessage());
                }
            }
        }
    }

    private function seedCalendarBlocks(): void
    {
        $blocks = [
            ['estrella', CalendarBlock::KIND_MAINTENANCE, 10, 12, 'Courtyard roof repair', 'Roofer booked for the courtyard gutter.'],
            ['carrera7', CalendarBlock::KIND_OWNER_STAY, 25, 28, 'Owner stay', 'Juan is using the apartment.'],
            ['pachamama', CalendarBlock::KIND_MAINTENANCE, 22, 23, 'Camera system upgrade', 'SegurCam replacing two garden cameras.'],
        ];

        foreach ($blocks as [$key, $kind, $from, $to, $title, $notes]) {
            CalendarBlock::query()->create([
                'organization_id' => $this->organization->getKey(),
                'property_id' => $this->properties[$key]->getKey(),
                'listing_id' => $this->listings[$key]->getKey(),
                'kind' => $kind,
                'start_date' => $this->today->addDays($from)->toDateString(),
                'end_date' => $this->today->addDays($to)->toDateString(),
                'title' => $title,
                'notes' => $notes,
                'owner_id' => $kind === CalendarBlock::KIND_OWNER_STAY ? $this->holder?->getKey() : null,
                'source' => 'manual',
            ]);
        }
    }

    /**
     * The inbox. Guest messages are recorded with automatic replies off, and
     * our replies as already delivered on Airbnb: nothing is sent anywhere.
     */
    private function seedConversations(): void
    {
        $service = app(ConversationService::class);

        $threads = [
            ['carrera7', ReservationStatus::CheckedIn, [
                ['in', 'Hola! Ya estamos en el apartamento. ¿Cómo se prende el calentador de agua?', 6],
                ['out', '¡Bienvenidos! El interruptor está en el panel junto a la puerta de la cocina, marcado "Calentador". Tarda unos 15 minutos en calentar.', 5],
                ['in', 'Perfecto, gracias. ¿Podemos hacer check-out a la 1 pm el domingo?', 1],
            ], null],
            ['casa62', ReservationStatus::CheckedIn, [
                ['in', 'Hi, two friends are joining us tonight for dinner. Is that OK?', 3],
            ], 'Reservation is for 6 adults and the house is at capacity. Visitors are not allowed: check the entrance camera tonight.'],
            ['hippies', ReservationStatus::Confirmed, [
                ['in', 'We land at 23:30 tomorrow. Is a late check-in possible?', 20],
                ['out', 'Of course. Check-in is self check-in with the digital lock, so any hour works. Your code arrives tomorrow morning.', 18],
            ], 'Late arrival: confirm the turnover clean finishes by 18:00.'],
            ['pachamama', ReservationStatus::Confirmed, [
                ['in', 'Buenas, somos 9 personas y llegamos en dos carros. ¿Hay parqueadero para los dos?', 30],
                ['out', 'Sí, hay parqueadero privado para dos carros dentro de la casa. Les enviamos el código del portón el día de llegada.', 28],
                ['in', '¡Genial! ¿A qué hora podemos llegar?', 2],
            ], null],
        ];

        foreach ($threads as [$key, $status, $messages, $note]) {
            $reservation = collect($this->bookings[$key] ?? [])->first(
                fn (Reservation $r): bool => $r->status === $status && ! $r->check_out_date->isPast(),
            );

            if ($reservation === null) {
                continue;
            }

            $conversation = $service->forReservation($reservation);

            foreach ($messages as [$direction, $body, $hoursAgo]) {
                if ($direction === 'in') {
                    $service->recordInbound($conversation, [
                        'body' => $body,
                        'channel' => 'airbnb',
                        'sent_at' => now()->subHours($hoursAgo),
                    ], allowAutomaticReply: false);
                } else {
                    $service->recordDeliveredElsewhere($conversation, [
                        'body' => $body,
                        'sent_at' => now()->subHours($hoursAgo),
                        'delivered_at' => now()->subHours($hoursAgo),
                    ]);
                }
            }

            if ($note !== null) {
                $service->addNote($conversation, $note);
            }
        }
    }

    private function seedOperations(): void
    {
        $tasks = app(TaskService::class);
        $cameraCheck = ChecklistTemplate::query()->where('name', 'Camera check')->first();
        $turnover = ChecklistTemplate::query()->where('kind', 'cleaning')->first();

        $tasks->create([
            'property_id' => $this->properties['casa62']->getKey(),
            'kind' => TaskKind::Maintenance,
            'priority' => TaskPriority::High,
            'title' => 'Entrance camera offline',
            'description' => 'The Tapo camera at the front door stopped streaming last night. Check power and Wi-Fi.',
            'scheduled_start' => $this->today->addDay()->setTime(9, 0),
            'due_at' => $this->today->addDay()->setTime(12, 0),
            'estimated_minutes' => 45,
        ]);

        $tasks->create([
            'property_id' => $this->properties['estrella']->getKey(),
            'kind' => TaskKind::Maintenance,
            'priority' => TaskPriority::Urgent,
            'title' => 'DMS camera 4 (rear access) not recording',
            'description' => 'Live view works but there are no recordings since Tuesday. Possibly a full memory card.',
            'scheduled_start' => $this->today->subDay()->setTime(10, 0),
            'due_at' => $this->today->subDay()->setTime(18, 0),
            'estimated_minutes' => 60,
        ]);

        $tasks->create([
            'property_id' => $this->properties['pachamama']->getKey(),
            'kind' => TaskKind::Inspection,
            'priority' => TaskPriority::Normal,
            'title' => 'Monthly check of all 10 cameras',
            'scheduled_start' => $this->today->addDays(3)->setTime(10, 0),
            'due_at' => $this->today->addDays(3)->setTime(16, 0),
            'estimated_minutes' => 90,
        ], $cameraCheck);

        $tasks->create([
            'property_id' => $this->properties['carrera7']->getKey(),
            'kind' => TaskKind::Maintenance,
            'priority' => TaskPriority::Normal,
            'title' => 'Digital lock battery at 20%',
            'description' => 'Replace the four AA batteries before the next arrival.',
            'scheduled_start' => $this->today->addDays(2)->setTime(14, 0),
            'due_at' => $this->today->addDays(4)->setTime(12, 0),
            'estimated_minutes' => 20,
        ]);

        $done = $tasks->create([
            'property_id' => $this->properties['hippies']->getKey(),
            'kind' => TaskKind::Cleaning,
            'priority' => TaskPriority::Normal,
            'title' => 'Turnover clean',
            'scheduled_start' => $this->today->subDays(6)->setTime(11, 0),
            'due_at' => $this->today->subDays(6)->setTime(15, 0),
            'estimated_minutes' => 150,
        ], $turnover);

        try {
            $tasks->complete($done, ['notes' => 'All rooms finished, no damage.'], force: true);
        } catch (Throwable $exception) {
            $this->command?->warn('Could not complete the sample clean: '.$exception->getMessage());
        }
    }

    private function seedExpenses(): void
    {
        $expenses = app(ExpenseService::class);

        $camera = $expenses->create([
            'property_id' => $this->properties['casa62']->getKey(),
            'expense_date' => $this->today->subDays(14)->toDateString(),
            'category' => 'maintenance',
            'description' => 'Replacement Tapo camera and mounting',
            'amount' => 4500,
            'currency' => self::CURRENCY,
            'billable_to' => 'owner',
            'notes' => 'SegurCam Bogotá, parts and installation.',
        ]);
        $expenses->approve($camera);

        $supplies = $expenses->create([
            'property_id' => $this->properties['pachamama']->getKey(),
            'expense_date' => $this->today->subDays(9)->toDateString(),
            'category' => 'supplies',
            'description' => 'Restock: coffee, toiletries, cleaning products',
            'amount' => 6000,
            'currency' => self::CURRENCY,
            'billable_to' => 'management',
        ]);
        $expenses->approve($supplies);

        $expenses->create([
            'property_id' => $this->properties['estrella']->getKey(),
            'expense_date' => $this->today->subDays(3)->toDateString(),
            'category' => 'maintenance',
            'description' => 'Electrician: courtyard lighting',
            'amount' => 9000,
            'currency' => self::CURRENCY,
            'billable_to' => 'owner',
            'notes' => 'Waiting for the invoice before approval.',
        ]);
    }

    private function seedReviews(): void
    {
        $service = app(ReviewService::class);

        $definitions = [
            ['carrera7', 5, 'Perfect base in Chapinero', 'Spotless, great location and the self check-in was easy.', true],
            ['casa62', 5, 'Ideal para familia', 'Casa muy cómoda y limpia, el patio es lo mejor. Volveríamos.', true],
            ['hippies', 4, 'Great loft, a bit noisy', 'Lovely space overlooking the park. Weekend nights are lively, bring earplugs.', false],
            ['pachamama', 5, 'Amazing for our group', 'Room for all ten of us, parking for both cars and a beautiful garden.', true],
            ['estrella', 5, 'Una joya en La Candelaria', 'Casa colonial preciosa, la terraza con vista a Monserrate es increíble.', false],
        ];

        foreach ($definitions as $index => [$key, $rating, $title, $comment, $answered]) {
            $reservation = collect($this->bookings[$key] ?? [])->first(
                fn (Reservation $r): bool => $r->status === ReservationStatus::CheckedOut,
            );

            $review = $service->import([
                'direction' => 'guest_to_host',
                'source' => 'airbnb',
                'external_id' => 'JL-REV-'.(100 + $index),
                'rating' => $rating,
                'rating_scale' => 5,
                'title' => $title,
                'public_comment' => $comment,
                'property_id' => $this->properties[$key]->getKey(),
                'reservation' => $reservation,
                'submitted_at' => $this->today->subDays(30 - ($index * 5)),
                'status' => Review::PUBLISHED,
            ]);

            if ($answered) {
                $service->respond($review, 'Thank you for staying with us. We are glad you enjoyed it and hope to welcome you back.');
            }
        }
    }

    /**
     * Last month's statement issued and paid; the month before left in draft.
     */
    private function seedStatements(): void
    {
        if ($this->holder === null) {
            return;
        }

        $builder = app(OwnerStatementBuilder::class);
        $payouts = app(OwnerPayoutService::class);

        $lastMonth = $this->today->subMonthNoOverflow()->startOfMonth();
        $monthBefore = $lastMonth->subMonthNoOverflow()->startOfMonth();

        try {
            $statement = $builder->approve($builder->build($this->holder, $lastMonth, $lastMonth->endOfMonth())->fresh());
            $payout = $payouts->fromStatement($statement->fresh());
            $payouts->markPaid($payout, 'TRF-'.$this->today->format('Ym').'-0001');
        } catch (Throwable $exception) {
            $this->command?->warn('Could not issue last month\'s statement: '.$exception->getMessage());
        }

        try {
            $builder->build($this->holder, $monthBefore, $monthBefore->endOfMonth());
        } catch (Throwable $exception) {
            $this->command?->warn('Could not draft the earlier statement: '.$exception->getMessage());
        }
    }
}
