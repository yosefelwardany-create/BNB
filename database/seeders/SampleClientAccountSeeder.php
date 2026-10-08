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
use App\Domain\Organization\Services\AccountRemoval;
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
use App\Domain\Properties\Models\PropertyPhoto;
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
 * A sample client account in Bogotá, built for a live platform.
 *
 * Each subclass supplies the account's content; this class decides how it is
 * written, and is careful about what leaves the database:
 *
 *  - No login. The account holder carries a placeholder address; the platform
 *    owner replaces it on Owners and grants portal access when ready.
 *  - No channel connection, so nothing is pushed to Airbnb or Hostex and no
 *    scheduled pull runs against the account.
 *  - Guest messages are recorded with automatic replies off, and our replies
 *    are recorded as already delivered: no email, no AI call, no channel send.
 *  - Payments are recorded as collected by Airbnb: no payment provider is
 *    called.
 *  - Guests use example.com addresses, a domain reserved so mail never arrives.
 *  - Photos are Pexels stock photos stored as links, as Airbnb imports are, so
 *    a redeploy cannot lose them; each is captioned as a sample.
 *
 * Bookings still go through the reservation service, so availability, rates,
 * turnovers and the client's figures are computed exactly as for real ones.
 * Dates are relative to the day it runs.
 *
 * It builds once, in one transaction: a deploy cut short keeps nothing. Once
 * the account exists a run only gives sample photos to a property that has
 * none, so real photos are never replaced, unless the account was built by an
 * older {@see version()} of the seeder, which is then rebuilt.
 */
abstract class SampleClientAccountSeeder extends Seeder
{
    protected const CURRENCY = 'USD';

    protected const TIMEZONE = 'America/Bogota';

    protected Organization $organization;

    protected CarbonImmutable $today;

    /** @var array<string, Property> */
    protected array $properties = [];

    /** @var array<string, Listing> */
    protected array $listings = [];

    /** @var array<string, list<Reservation>> */
    protected array $bookings = [];

    protected ?Owner $holder = null;

    abstract protected function slug(): string;

    abstract protected function accountName(): string;

    /**
     * @return array{first_name: string, last_name: string, email: string, phone: string, bank_name: string, bank_account_name: string}
     */
    abstract protected function holder(): array;

    /**
     * @return array{name: string, slug: string, description: string, color: string}
     */
    abstract protected function portfolio(): array;

    /**
     * Keyed by a short property key. Each definition: name, reference, type,
     * address [line 1, line 2|null, postal code], neighbourhood, coordinates
     * [lat, lng], bedrooms, bathrooms, beds, occupancy, size, floor, rate,
     * cleaning, deposit, summary, space, secrets, method, amenities, bot,
     * photos (Pexels ids, cover first), and either cameras [count, app, what to
     * monitor] or notes.
     *
     * @return array<string, array<string, mixed>>
     */
    abstract protected function propertyDefinitions(): array;

    /** @return list<array{0: string, 1: string, 2: string, 3: string, 4: string}> [first, last, country, phone, language] */
    abstract protected function guests(): array;

    /** @return list<array{0: string, 1: int, 2: int, 3: int, 4: int, 5: ReservationStatus}> [property, guest, days to check-in, nights, adults, status] */
    abstract protected function bookingPlan(): array;

    /** @return array{0: string, 1: int, 2: int, 3: int, 4: int}|null [property, guest, days to check-in, nights, adults] */
    abstract protected function cancellation(): ?array;

    /** @return list<array{0: string, 1: string, 2: int, 3: int, 4: string, 5: string}> [property, kind, from day, to day, title, notes] */
    abstract protected function calendarBlocks(): array;

    /** @return list<array{0: string, 1: ReservationStatus, 2: list<array{0: string, 1: string, 2: int}>, 3: string|null}> */
    abstract protected function conversations(): array;

    /**
     * @return list<array{property: string, kind: TaskKind, priority: TaskPriority, title: string, description?: string, start: array{0: int, 1: int}, due: array{0: int, 1: int}, minutes: int, checklist?: string, complete?: bool}>
     */
    abstract protected function tasks(): array;

    /**
     * @return list<array{property: string, days_ago: int, category: string, description: string, amount: int, billable_to: string, notes?: string, approve: bool}>
     */
    abstract protected function expenses(): array;

    /** @return list<array{0: string, 1: int, 2: string, 3: string, 4: bool}> [property, rating out of 5, title, comment, answered] */
    abstract protected function reviews(): array;

    /**
     * Which version of the sample data this seeder builds.
     *
     * Raise it when the data changes enough that an account already built
     * should be replaced: the next run removes the older account and builds
     * this one in its place. It is a sample, so nobody's records are lost.
     */
    protected function version(): int
    {
        return 1;
    }

    public function run(): void
    {
        $tenancy = app(TenantContext::class);

        $existing = $tenancy->withoutScope(
            fn () => Organization::query()->where('slug', $this->slug())->first(),
        );

        $outdated = $existing !== null && (int) $existing->setting('sample.version', 1) < $this->version();

        if ($existing !== null && ! $outdated) {
            $this->command?->warn(sprintf('The %s sample account already exists. Its data was left as it is.', $this->accountName()));
            $this->addMissingPhotos($existing);

            return;
        }

        $this->today = CarbonImmutable::today(self::TIMEZONE);

        // All or nothing: a deploy cut short leaves no half-built account, and
        // the next run starts clean. Run it with the sync queue so the listeners
        // it triggers (turnover cleans) run inside the same transaction. An
        // account built by an older version of this seeder is replaced in the
        // same transaction, so it is never gone without its replacement.
        DB::transaction(function () use ($existing, $outdated, $tenancy): void {
            if ($outdated) {
                app(AccountRemoval::class)->remove($existing);
            }

            $this->build($tenancy);
        });

        if ($outdated) {
            $this->command?->info(sprintf('The %s sample account was rebuilt with the current sample data.', $this->accountName()));
        }

        $this->command?->info(sprintf(
            '%s sample account created: %d properties, %d bookings.',
            $this->accountName(),
            count($this->properties),
            array_sum(array_map('count', $this->bookings)),
        ));

        $this->addMissingPhotos($this->organization);
    }

    private function build(TenantContext $tenancy): void
    {
        $holder = $this->holder();

        $this->organization = app(OrganizationProvisioner::class)->createOrganization([
            'name' => $this->accountName(),
            'legal_name' => $this->accountName(),
            'slug' => $this->slug(),
            'status' => 'active',
            'trial_ends_at' => null,
            'base_currency' => self::CURRENCY,
            'timezone' => self::TIMEZONE,
            'locale' => 'es',
            'country_code' => 'CO',
            'contact_email' => $holder['email'],
            'contact_phone' => $holder['phone'],
        ]);
        $this->organization->putSetting('sample.version', $this->version());
        $this->organization->save();

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

        foreach ([
            ['Limpieza Andina', 'cleaning', 'Marcela Ríos', 'contacto@limpieza-andina.example.com', '+57 300 111 1111', ['hourly_rate' => 800]],
            ['SegurCam Bogotá', 'security', 'Andrés Gómez', 'soporte@segurcam.example.com', '+57 300 222 2222', ['callout_fee' => 2500]],
            ['Cerrajería El Lago', 'locksmith', 'Jorge Peña', 'servicio@cerrajeria-ellago.example.com', '+57 300 333 3333', ['callout_fee' => 2000]],
        ] as [$name, $category, $contact, $email, $phone, $price]) {
            Vendor::query()->create([
                'organization_id' => $organization,
                'name' => $name,
                'category' => $category,
                'contact_name' => $contact,
                'email' => $email,
                'phone' => $phone,
                'city' => 'Bogotá',
                'country_code' => 'CO',
                'currency' => self::CURRENCY,
                'is_active' => true,
                ...$price,
            ]);
        }

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
        $properties = app(PropertyService::class);
        $listings = app(ListingService::class);
        $briefs = app(AgentBriefStore::class);

        $portfolio = Portfolio::query()->create([
            'organization_id' => $this->organization->getKey(),
            ...$this->portfolio(),
            'is_active' => true,
        ]);

        $standardRules = "No parties or events.\nNo smoking indoors.\nQuiet hours 22:00–07:00.\n"
            .'Only the guests on the reservation may stay or visit. The entrance is recorded by a security camera.';

        foreach ($this->propertyDefinitions() as $key => $definition) {
            $cameraCount = $definition['cameras'][0] ?? 1;

            $notes = isset($definition['cameras'])
                ? sprintf(
                    "Security cameras: %d (%s app).\nWhat to monitor: %s\nCameras cover entrances and common areas only, never bedrooms or bathrooms.",
                    $definition['cameras'][0],
                    $definition['cameras'][1],
                    $definition['cameras'][2],
                )
                : $definition['notes'];

            $amenityIds = Amenity::query()->whereIn('key', $definition['amenities'])->pluck('id')->all();

            $property = $properties->create([
                'portfolio_id' => $portfolio->getKey(),
                'name' => $definition['name'],
                'internal_name' => $definition['name'].' ('.$this->accountName().')',
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
                'internal_notes' => $notes,
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
        $details = $this->holder();

        $holder = $clients->ensureAccountHolder($this->organization, [
            'first_name' => $details['first_name'],
            'last_name' => $details['last_name'],
            'email' => $details['email'],
        ]);

        $holder->forceFill([
            'phone' => $details['phone'],
            'payout_method' => 'bank_transfer',
            'payout_currency' => self::CURRENCY,
            'statement_frequency' => 'monthly',
            'statement_day' => 1,
            'bank_name' => $details['bank_name'],
            'bank_account_name' => $details['bank_account_name'],
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
        $guests = [];

        foreach ($this->guests() as [$first, $last, $country, $phone, $language]) {
            $guests[] = $directory->findOrCreate([
                'first_name' => $first,
                'last_name' => $last,
                'email' => strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $first.'.'.$last) ?: $first.'.'.$last).'@example.com',
                'phone' => $phone,
                'country_code' => $country,
                'language' => $language,
            ]);
        }

        $service = app(ReservationService::class);

        foreach ($this->bookingPlan() as [$key, $guestIndex, $offset, $nights, $adults, $status]) {
            $reservation = $this->book($service, $key, $guests[$guestIndex], $offset, $nights, $adults, $status);

            if ($reservation !== null) {
                $this->bookings[$key][] = $reservation;
            }
        }

        // A cancellation, so the cancelled state and its zero revenue show.
        $cancellation = $this->cancellation();

        if ($cancellation !== null) {
            [$key, $guestIndex, $offset, $nights, $adults] = $cancellation;
            $cancelled = $this->book($service, $key, $guests[$guestIndex], $offset, $nights, $adults, ReservationStatus::Confirmed);

            if ($cancelled !== null) {
                $service->cancel($cancelled, reason: 'Guest cancelled: change of travel plans.', cancelledBy: 'guest');
            }
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
        foreach ($this->calendarBlocks() as [$key, $kind, $from, $to, $title, $notes]) {
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

        foreach ($this->conversations() as [$key, $status, $messages, $note]) {
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
        $service = app(TaskService::class);

        foreach ($this->tasks() as $definition) {
            $checklist = isset($definition['checklist'])
                ? ChecklistTemplate::query()->where('name', $definition['checklist'])->first()
                : null;

            $task = $service->create(array_filter([
                'property_id' => $this->properties[$definition['property']]->getKey(),
                'kind' => $definition['kind'],
                'priority' => $definition['priority'],
                'title' => $definition['title'],
                'description' => $definition['description'] ?? null,
                'scheduled_start' => $this->today->addDays($definition['start'][0])->setTime($definition['start'][1], 0),
                'due_at' => $this->today->addDays($definition['due'][0])->setTime($definition['due'][1], 0),
                'estimated_minutes' => $definition['minutes'],
            ], static fn (mixed $value): bool => $value !== null), $checklist);

            if ($definition['complete'] ?? false) {
                try {
                    $service->complete($task, ['notes' => 'All rooms finished, no damage.'], force: true);
                } catch (Throwable $exception) {
                    $this->command?->warn('Could not complete a sample task: '.$exception->getMessage());
                }
            }
        }
    }

    private function seedExpenses(): void
    {
        $service = app(ExpenseService::class);

        foreach ($this->expenses() as $definition) {
            $expense = $service->create(array_filter([
                'property_id' => $this->properties[$definition['property']]->getKey(),
                'expense_date' => $this->today->subDays($definition['days_ago'])->toDateString(),
                'category' => $definition['category'],
                'description' => $definition['description'],
                'amount' => $definition['amount'],
                'currency' => self::CURRENCY,
                'billable_to' => $definition['billable_to'],
                'notes' => $definition['notes'] ?? null,
            ], static fn (mixed $value): bool => $value !== null));

            if ($definition['approve']) {
                $service->approve($expense);
            }
        }
    }

    private function seedReviews(): void
    {
        $service = app(ReviewService::class);
        $prefix = strtoupper(substr(str_replace('-', '', $this->slug()), 0, 6));

        foreach ($this->reviews() as $index => [$key, $rating, $title, $comment, $answered]) {
            $reservation = collect($this->bookings[$key] ?? [])->first(
                fn (Reservation $r): bool => $r->status === ReservationStatus::CheckedOut,
            );

            $review = $service->import([
                'direction' => 'guest_to_host',
                'source' => 'airbnb',
                'external_id' => $prefix.'-REV-'.(100 + $index),
                'rating' => $rating,
                'rating_scale' => 5,
                'title' => $title,
                'public_comment' => $comment,
                'property_id' => $this->properties[$key]->getKey(),
                'reservation' => $reservation,
                'submitted_at' => $this->today->subDays(30 - min(29, $index * 3)),
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

    /**
     * Sample photos for every property that has none: Pexels stock photos
     * stored as links. A property that already has photos is left alone.
     */
    private function addMissingPhotos(Organization $organization): void
    {
        $definitions = $this->propertyDefinitions();

        $added = app(TenantContext::class)->runAs($organization, fn (): int => DB::transaction(function () use ($definitions): int {
            $added = 0;

            foreach ($definitions as $definition) {
                $property = Property::query()->where('reference', $definition['reference'])->first();

                if ($property === null || PropertyPhoto::query()->where('property_id', $property->getKey())->exists()) {
                    continue;
                }

                foreach ($definition['photos'] as $position => $id) {
                    PropertyPhoto::query()->create([
                        'organization_id' => $property->organization_id,
                        'property_id' => $property->getKey(),
                        'disk' => 'external',
                        'path' => 'sample:pexels:'.$id,
                        'external_url' => sprintf(
                            'https://images.pexels.com/photos/%1$d/pexels-photo-%1$d.jpeg?auto=compress&cs=tinysrgb&w=1600',
                            $id,
                        ),
                        'caption' => 'Sample photo (Pexels), not a photograph of this property.',
                        'position' => $position + 1,
                        'is_cover' => $position === 0,
                    ]);

                    $added++;
                }
            }

            return $added;
        }));

        if ($added > 0) {
            $this->command?->info(sprintf('Added %d sample photos to the %s properties.', $added, $this->accountName()));
        }
    }
}
