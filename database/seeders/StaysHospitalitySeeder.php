<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Accounting\Services\ChartOfAccountsInstaller;
use App\Domain\Agents\Services\AgentBriefStore;
use App\Domain\Guests\Models\Guest;
use App\Domain\Guests\Services\GuestDirectory;
use App\Domain\Listings\Models\Listing;
use App\Domain\Listings\Services\ListingService;
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
 * Stays Hospitality, a prospective client in Cairo, built from their own public
 * booking site (stayshospitality.com, a Hostaway booking engine), with sample
 * activity so every screen of the demo has something in it.
 *
 * Two kinds of content, kept apart:
 *
 *  - **From their site** (data/stays-hospitality.json): names, areas, rooms,
 *    amenities, rules and photos. Photos are links to Hostaway's image server,
 *    stored the way channel imports are.
 *  - **Sample, invented here**: nightly rates (their site shows none), guests,
 *    bookings, payments, messages, cleans, costs, reviews and statements.
 *    Guests use example.com addresses and every booking carries an internal
 *    note saying it is sample data.
 *
 * The properties with headline figures only are left as drafts with no rate,
 * so the readiness check still has something to refuse in the demo.
 *
 * Nothing leaves the database: no login, no channel connection, automatic
 * replies off, our replies recorded as delivered, payments recorded rather
 * than charged. The account holder has a placeholder address.
 *
 * It builds once, in one transaction. With the account already there it only
 * gives photos to a property that has none, so nothing a person changed is
 * overwritten.
 */
class StaysHospitalitySeeder extends Seeder
{
    public const SLUG = 'stays-hospitality';

    private const NAME = 'Stays Hospitality';

    private const CURRENCY = 'EGP';

    private const TIMEZONE = 'Africa/Cairo';

    private const SAMPLE_NOTE = 'Sample booking for the demo, not a real reservation.';

    /** Sample nightly rate in piastres by bedrooms (their site publishes none). */
    private const RATES = [1 => 350000, 2 => 500000, 3 => 700000, 4 => 950000, 5 => 1400000];

    /** How long people stay: mostly short, the odd week. */
    private const NIGHTS = [2, 2, 3, 3, 3, 4, 4, 5, 6, 7];

    private CarbonImmutable $today;

    private ?Owner $holder = null;

    /** @var array<int, array{property: Property, listing: Listing, definition: array<string, mixed>}> */
    private array $active = [];

    /** @var array<int, list<Reservation>> */
    private array $bookings = [];

    public function run(): void
    {
        $data = $this->data();
        $tenancy = app(TenantContext::class);

        $existing = $tenancy->withoutScope(
            fn () => Organization::query()->where('slug', self::SLUG)->first(),
        );

        if ($existing !== null) {
            $this->command?->warn('The Stays Hospitality account already exists. Its data was left as it is.');
            $this->addMissingPhotos($existing, $data);

            return;
        }

        $this->today = CarbonImmutable::today(self::TIMEZONE);

        // All or nothing. Run it with the sync queue so the listeners it
        // triggers (turnover cleans) run inside the same transaction.
        $organization = DB::transaction(function () use ($data, $tenancy): Organization {
            $organization = app(OrganizationProvisioner::class)->createOrganization([
                'name' => self::NAME,
                'legal_name' => self::NAME,
                'slug' => self::SLUG,
                'status' => 'active',
                'trial_ends_at' => null,
                'base_currency' => self::CURRENCY,
                'timezone' => self::TIMEZONE,
                'locale' => 'en',
                'country_code' => 'EG',
                'contact_email' => 'stays.hospitality@example.com',
            ]);
            $organization->putSetting('sample.activity', true);
            $organization->save();

            $tenancy->runAs($organization, function () use ($organization, $data): void {
                app(ChartOfAccountsInstaller::class)->install($organization);
                app(CancellationPolicyInstaller::class)->install($organization);

                $this->seedCatalogue($organization);
                $this->seedAccountHolder($organization);
                $this->seedProperties($organization, $data);
                $this->seedBookings();
                $this->seedConversations();
                $this->seedOperations();
                $this->seedExpenses();
                $this->seedReviews();
                $this->seedStatements();
            });

            return $organization;
        });

        $this->addMissingPhotos($organization, $data);

        $this->command?->info(sprintf(
            'Stays Hospitality created: %d properties (%d on sale), %d sample bookings.',
            count($data['properties']),
            count($this->active),
            array_sum(array_map('count', $this->bookings)),
        ));
    }

    private function seedCatalogue(Organization $organization): void
    {
        $id = $organization->getKey();

        RatePlan::query()->create([
            'organization_id' => $id,
            'name' => 'Standard',
            'slug' => 'standard',
            'description' => 'The published nightly rate.',
            'currency' => self::CURRENCY,
            'derivation_type' => 'independent',
            'is_default' => true,
            'is_active' => true,
            'priority' => 100,
        ]);

        // Thursday and Friday nights are the Egyptian weekend.
        PricingRule::query()->create([
            'organization_id' => $id,
            'name' => 'Weekend uplift',
            'description' => 'Thursday and Friday nights.',
            'kind' => 'day_of_week',
            'days_of_week' => [4, 5],
            'adjustment_type' => 'percentage',
            'adjustment_value' => 15,
            'priority' => 10,
            'is_active' => true,
        ]);

        PricingRule::query()->create([
            'organization_id' => $id,
            'name' => 'Weekly discount',
            'description' => 'Seven nights or more.',
            'kind' => 'length_of_stay',
            'conditions' => ['minimum_nights' => 7],
            'adjustment_type' => 'percentage',
            'adjustment_value' => -10,
            'floor_rate' => 300000,
            'priority' => 30,
            'is_active' => true,
        ]);

        ChecklistTemplate::query()->create([
            'organization_id' => $id,
            'name' => 'Turnover clean',
            'kind' => 'cleaning',
            'description' => 'Between one guest leaving and the next arriving.',
            'items' => [
                ['label' => 'Strip and remake all beds', 'requires_photo' => false],
                ['label' => 'Bathrooms: clean, restock, fresh towels', 'requires_photo' => false],
                ['label' => 'Kitchen: empty fridge, wash dishes, wipe surfaces', 'requires_photo' => false],
                ['label' => 'Check for damage and items left behind', 'requires_photo' => false],
                ['label' => 'Photograph each finished room', 'requires_photo' => true],
            ],
            'is_default' => true,
            'is_active' => true,
        ]);

        foreach ([
            ['Sparkle Home Cleaning', 'cleaning', 'Mona Adel', 'bookings@sparkle-home.example.com', '+20 100 000 1001', ['hourly_rate' => 15000]],
            ['Cairo Fix Maintenance', 'maintenance', 'Karim Fathy', 'service@cairofix.example.com', '+20 100 000 1002', ['callout_fee' => 50000]],
        ] as [$name, $category, $contact, $email, $phone, $price]) {
            Vendor::query()->create([
                'organization_id' => $id,
                'name' => $name,
                'category' => $category,
                'contact_name' => $contact,
                'email' => $email,
                'phone' => $phone,
                'city' => 'New Cairo',
                'country_code' => 'EG',
                'currency' => self::CURRENCY,
                'is_active' => true,
                ...$price,
            ]);
        }
    }

    /**
     * The client: owns every property outright under the 10% agreement, dated
     * back so the sample history is attributed and commissioned.
     */
    private function seedAccountHolder(Organization $organization): void
    {
        $clients = app(ClientAccounts::class);

        $holder = $clients->ensureAccountHolder($organization, [
            'first_name' => 'Stays',
            'last_name' => 'Hospitality',
            'email' => 'stays.hospitality@example.com',
        ]);
        $holder->forceFill([
            'payout_method' => 'bank_transfer',
            'payout_currency' => self::CURRENCY,
            'statement_frequency' => 'monthly',
            'statement_day' => 1,
        ])->save();

        $clients->ensureAgreement($organization, $holder, $this->since());

        $this->holder = $holder->fresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function seedProperties(Organization $organization, array $data): void
    {
        $properties = app(PropertyService::class);
        $listings = app(ListingService::class);
        $briefs = app(AgentBriefStore::class);

        $portfolio = Portfolio::query()->create([
            'organization_id' => $organization->getKey(),
            'name' => 'Cairo',
            'slug' => 'cairo',
            'description' => 'New Cairo compounds and Almaza.',
            'color' => '#B8860B',
            'is_active' => true,
        ]);

        foreach ($data['properties'] as $definition) {
            $details = $definition['details'] ?? [];
            $notes = $definition['notes'] ?? [];
            $summary = $definition['summary'] ?? $definition['name'].'.';
            $description = trim($summary."\n\n".implode("\n", $details));
            $almaza = $definition['area'] === 'Almaza';
            $guests = (int) $definition['guests'];
            $bedrooms = (int) $definition['bedrooms'];
            $beds = (int) ($definition['beds'] ?? $bedrooms);
            $complete = $details !== [] && $definition['photos'] !== [];
            $rate = $complete ? self::RATES[min(5, max(1, $bedrooms))] : 0;
            $cleaning = $complete ? 40000 + 20000 * $bedrooms : 0;

            // The site's rules allow smoking inside; some listings say otherwise.
            $rules = array_map(
                fn (string $rule): string => ($definition['smoking'] ?? true) === false && str_starts_with($rule, 'Smoking')
                    ? 'No smoking inside.' : $rule,
                $data['house_rules'],
            );

            $property = $properties->create([
                'portfolio_id' => $portfolio->getKey(),
                'name' => $definition['name'],
                'internal_name' => $definition['name'].' ('.self::NAME.')',
                'reference' => 'SH-'.$definition['id'],
                'property_type' => 'apartment',
                'rental_kind' => 'entire_place',
                'address_line_1' => $definition['compound'] ?? $definition['area'],
                'city' => $almaza ? 'Cairo' : 'New Cairo',
                'state' => 'Cairo Governorate',
                'country_code' => 'EG',
                'neighbourhood' => $almaza ? 'Almaza, Heliopolis' : ($definition['compound'] ?? 'New Cairo'),
                'timezone' => self::TIMEZONE,
                'currency' => self::CURRENCY,
                'bedrooms' => $bedrooms,
                'bathrooms' => $definition['bathrooms'],
                'beds' => $beds,
                'max_occupancy' => $guests,
                'max_adults' => $guests,
                'max_children' => max(0, $guests - 2),
                'max_infants' => 1,
                'max_pets' => ($definition['pets'] ?? false) ? 1 : 0,
                'size_value' => $definition['size'] ?? null,
                'size_unit' => isset($definition['size']) ? 'sqm' : null,
                'floor' => $definition['floor'] ?? null,
                'summary' => $summary,
                'description' => $description,
                'space_description' => implode("\n", $details) ?: null,
                'house_rules' => trim(implode("\n", $rules)."\n".implode("\n", $notes)),
                'internal_notes' => trim(
                    'From the client\'s booking site: https://www.stayshospitality.com/listings/'.$definition['id']."\n"
                    .$data['extras']."\n"
                    .($complete
                        ? 'The nightly rate and cleaning fee are sample figures: their site does not publish prices.'
                        : 'Only the headline figures were collected; full details, photos and a rate still to come.'),
                ),
                'check_in_time' => '15:00',
                'check_out_time' => '11:00',
                'check_in_until' => '23:00',
                'check_in_instructions' => 'Show your ID at the compound gate. We send the apartment access details on the day of arrival.',
                'check_out_instructions' => 'Leave the keys inside, close the windows and switch off the AC and lights.',
                'base_rate' => $rate,
                'cleaning_fee' => $cleaning,
                'minimum_nights' => 2,
                'maximum_nights' => 60,
                'cleaning_duration_minutes' => $bedrooms >= 3 ? 210 : 150,
                'preparation_hours' => 3,
                'instant_book' => true,
            ], Amenity::query()->whereIn('key', $definition['amenities'] ?? [])->pluck('id')->all());

            $listing = $listings->primaryFor($property, array_filter([
                'title' => $definition['name'],
                'summary' => $summary,
                'description' => $description,
                'max_occupancy' => $guests,
                'bedrooms' => $bedrooms,
                'bathrooms' => $definition['bathrooms'],
                'beds' => $beds,
                'base_rate' => $complete ? $rate : null,
                'cleaning_fee' => $complete ? $cleaning : null,
                'minimum_nights' => 2,
                'instant_book' => true,
            ], static fn (mixed $value): bool => $value !== null), reason: 'Imported from the client\'s booking site');

            app(OwnershipLedger::class)->assign($property, $this->holder, [
                'ownership_percentage' => 100,
                'is_primary' => true,
                'starts_on' => $this->since()->toDateString(),
            ]);

            if (! $complete) {
                continue;
            }

            $properties->activate($property);
            $property->forceFill(['activated_at' => $this->since()])->save();

            $briefs->save($property, [
                'enabled' => true,
                // Drafts only: nothing is sent to a guest without a person.
                'automatic_guest_replies' => false,
                'auto_send' => [],
                'bot_name' => 'Stays Assistant',
                'persona' => 'Warm and brief. Replies in Arabic or English, whichever the guest wrote in.',
                'languages' => ['en', 'ar'],
                'escalate' => ['refund', 'party', 'extra guests', 'noise complaint', 'marriage certificate', 'damage'],
                'never' => ['share access details before the day of arrival', 'offer a discount', 'allow visitors who are not on the reservation'],
                'extra_knowledge' => trim(implode("\n", $notes)."\n".$data['extras']),
            ]);

            $this->active[$definition['id']] = [
                'property' => $property->fresh(),
                'listing' => $listing->fresh(),
                'definition' => $definition,
            ];
        }
    }

    private function seedBookings(): void
    {
        $directory = app(GuestDirectory::class);
        $guests = [];

        foreach ([
            ['Ahmed', 'Hassan', 'EG', '+20 100 555 0101', 'ar'],
            ['Nour', 'El-Sayed', 'EG', '+20 100 555 0102', 'ar'],
            ['Omar', 'Farouk', 'EG', '+20 100 555 0103', 'ar'],
            ['Layla', 'Mansour', 'EG', '+20 100 555 0104', 'en'],
            ['Khalid', 'Al-Otaibi', 'SA', '+966 50 555 0105', 'ar'],
            ['Fatima', 'Al-Mazrouei', 'AE', '+971 50 555 0106', 'ar'],
            ['Yousef', 'Al-Sabah', 'KW', '+965 5555 0107', 'ar'],
            ['Sarah', 'Thompson', 'GB', '+44 7700 900108', 'en'],
            ['Lukas', 'Becker', 'DE', '+49 151 5550109', 'en'],
            ['Marco', 'Rossi', 'IT', '+39 345 555 0110', 'en'],
            ['Emily', 'Carter', 'US', '+1 202 555 0111', 'en'],
            ['Hana', 'Kamal', 'EG', '+20 100 555 0112', 'en'],
            ['Tarek', 'Nabil', 'EG', '+20 100 555 0113', 'ar'],
            ['Sophie', 'Martin', 'FR', '+33 6 55 55 01 14', 'en'],
        ] as [$first, $last, $country, $phone, $language]) {
            $guests[] = $directory->findOrCreate([
                'first_name' => $first,
                'last_name' => $last,
                'email' => strtolower(str_replace('-', '', $first.'.'.$last)).'@example.com',
                'phone' => $phone,
                'country_code' => $country,
                'language' => $language,
            ]);
        }

        $service = app(ReservationService::class);

        foreach ($this->active as $id => $entry) {
            // Repeatable per property, so a rebuild looks the same.
            mt_srand($id);
            $day = -45 + mt_rand(0, 4);
            $lastGuest = -1;

            while ($day < 40) {
                $nights = self::NIGHTS[mt_rand(0, count(self::NIGHTS) - 1)];
                $checkOut = $day + $nights;

                do {
                    $guest = mt_rand(0, count($guests) - 1);
                } while ($guest === $lastGuest);
                $lastGuest = $guest;

                $occupancy = (int) $entry['definition']['guests'];
                $adults = [2, 2, 2, min(3, $occupancy), min(4, $occupancy), $occupancy][mt_rand(0, 5)];
                $source = ['airbnb', 'airbnb', 'airbnb', 'booking_com', 'booking_com', 'direct'][mt_rand(0, 5)];

                $status = match (true) {
                    $checkOut <= 0 => ReservationStatus::CheckedOut,
                    $day <= 0 => ReservationStatus::CheckedIn,
                    default => ReservationStatus::Confirmed,
                };

                $reservation = $this->book($service, $entry['listing'], $guests[$guest], $day, $nights, $adults, $status, $source);

                if ($reservation !== null) {
                    $this->bookings[$id][] = $reservation;
                }

                // Turnover day, then a gap of empty nights.
                $day = $checkOut + [0, 0, 1, 1, 2, 3, 5][mt_rand(0, 6)];
            }

            mt_srand();
        }

        // One cancellation, so the cancelled state and its zero revenue show.
        $first = array_key_first($this->active);
        $cancelled = $this->book($service, $this->active[$first]['listing'], $guests[8], 52, 4, 2, ReservationStatus::Confirmed, 'booking_com');
        if ($cancelled !== null) {
            $service->cancel($cancelled, reason: 'Guest cancelled: change of travel plans.', cancelledBy: 'guest');
        }

        $this->recordPayments();
    }

    private function book(
        ReservationService $service,
        Listing $listing,
        Guest $guest,
        int $offset,
        int $nights,
        int $adults,
        ReservationStatus $status,
        string $source,
    ): ?Reservation {
        try {
            return $service->create(new ReservationRequest(
                listing: $listing,
                checkIn: $this->today->addDays($offset),
                checkOut: $this->today->addDays($offset + $nights),
                adults: $adults,
                status: $status,
                source: $source,
                guest: $guest,
                internalNotes: self::SAMPLE_NOTE,
                bookedAt: $this->today->addDays($offset)->subDays(mt_rand(5, 40)),
                recordsExistingStay: $offset <= 0,
            ));
        } catch (Throwable $exception) {
            $this->command?->warn(sprintf('Skipped a sample booking (%+d days): %s', $offset, $exception->getMessage()));

            return null;
        }
    }

    /**
     * Airbnb collects the guest's money; Booking.com and direct guests pay us.
     * Direct bookings arriving in the next three weeks are left unpaid, so the
     * dashboard has something awaiting payment.
     */
    private function recordPayments(): void
    {
        $payments = app(PaymentService::class);

        foreach ($this->bookings as $reservations) {
            foreach ($reservations as $reservation) {
                $due = $reservation->grandTotal();
                $arrivesIn = (int) $this->today->diffInDays($reservation->check_in_date, false);

                if ($due->minorUnits <= 0 || ($reservation->source === 'direct' && $arrivesIn > 0 && $arrivesIn <= 21)) {
                    continue;
                }

                $byAirbnb = $reservation->source === 'airbnb';

                try {
                    $payments->recordExternalPayment($due, $reservation, [
                        'method' => $byAirbnb ? 'channel' : 'bank_transfer',
                        'provider_reference' => ($byAirbnb ? 'AIRBNB-' : 'TRF-').$reservation->confirmation_code,
                        'description' => $byAirbnb ? 'Collected by Airbnb (sample)' : 'Bank transfer from the guest (sample)',
                        'is_collected_by_us' => ! $byAirbnb,
                    ]);
                } catch (Throwable $exception) {
                    $this->command?->warn('Could not record a sample payment: '.$exception->getMessage());
                }
            }
        }
    }

    /**
     * The inbox. Guest messages are recorded with automatic replies off and
     * our replies as already delivered: nothing is sent anywhere.
     */
    private function seedConversations(): void
    {
        $service = app(ConversationService::class);

        $threads = [
            [ReservationStatus::CheckedIn, [
                ['in', 'Hi, we arrived. The AC in the second bedroom is not cooling much, is there a remote somewhere?', 3],
            ], 'Asked Cairo Fix to check the second bedroom AC if the remote does not solve it.'],
            [ReservationStatus::Confirmed, [
                ['in', 'Hello! Is there parking for two cars? And can we check in around 1 pm?', 26],
                ['out', 'Hello and welcome! There is free underground parking for one car and street parking nearby. Early check-in depends on the previous guest; we will confirm the day before.', 24],
                ['in', 'Perfect, thank you. Could you also add a baby crib please?', 2],
            ], null],
            [ReservationStatus::Confirmed, [
                ['in', 'Do we need to bring anything for the compound gate? We are two couples.', 5],
            ], 'Remind them about IDs at the gate and the marriage certificate rule for Egyptian/Arab couples.'],
        ];

        $used = [];

        foreach ($threads as [$status, $messages, $note]) {
            $reservation = null;

            foreach ($this->bookings as $reservations) {
                foreach ($reservations as $candidate) {
                    if ($candidate->status === $status && ! isset($used[$candidate->getKey()])
                        && ($status !== ReservationStatus::Confirmed || $candidate->check_in_date->lte($this->today->addDays(7)))) {
                        $reservation = $candidate;
                        break 2;
                    }
                }
            }

            if ($reservation === null) {
                continue;
            }

            $used[$reservation->getKey()] = true;
            $channel = $reservation->source === 'direct' ? 'email' : $reservation->source;
            $conversation = $service->forReservation($reservation);

            foreach ($messages as [$direction, $body, $hoursAgo]) {
                if ($direction === 'in') {
                    $service->recordInbound($conversation, [
                        'body' => $body,
                        'channel' => $channel,
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

    /** Cleans come from the bookings; these are the jobs that do not. */
    private function seedOperations(): void
    {
        $service = app(TaskService::class);
        $ids = array_keys($this->active);

        foreach ([
            [0, TaskKind::Maintenance, TaskPriority::High, 'Second bedroom AC not cooling', 'Guest in house reported weak cooling. Check the filter and gas.', 0, 60],
            [1, TaskKind::Restocking, TaskPriority::Normal, 'Restock Nespresso capsules and water', null, 1, 30],
            [3, TaskKind::Inspection, TaskPriority::Normal, 'Monthly inspection before the summer season', 'Pool towels, balcony furniture, smoke alarm.', 3, 90],
            [5, TaskKind::GuestRequest, TaskPriority::Normal, 'Deliver baby crib before arrival', 'Requested in the inbox.', 2, 30],
        ] as [$index, $kind, $priority, $title, $description, $day, $minutes]) {
            if (! isset($ids[$index])) {
                continue;
            }

            $service->create(array_filter([
                'property_id' => $this->active[$ids[$index]]['property']->getKey(),
                'kind' => $kind,
                'priority' => $priority,
                'title' => $title,
                'description' => $description,
                'scheduled_start' => $this->today->addDays($day)->setTime(10, 0),
                'due_at' => $this->today->addDays($day)->setTime(14, 0),
                'estimated_minutes' => $minutes,
            ], static fn (mixed $value): bool => $value !== null));
        }
    }

    private function seedExpenses(): void
    {
        $service = app(ExpenseService::class);
        $ids = array_keys($this->active);

        foreach ([
            [0, 18, 'maintenance', 'AC service, two split units', 180000, true],
            [2, 12, 'supplies', 'Bed linen and towels replacement', 240000, true],
            [4, 6, 'utilities', 'Electricity top-up', 95000, true],
            [6, 2, 'maintenance', 'Kitchen tap replacement', 65000, false],
        ] as [$index, $daysAgo, $category, $description, $amount, $approve]) {
            if (! isset($ids[$index])) {
                continue;
            }

            $expense = $service->create([
                'property_id' => $this->active[$ids[$index]]['property']->getKey(),
                'expense_date' => $this->today->subDays($daysAgo)->toDateString(),
                'category' => $category,
                'description' => $description.' (sample)',
                'amount' => $amount,
                'currency' => self::CURRENCY,
                'billable_to' => 'owner',
            ]);

            if ($approve) {
                $service->approve($expense);
            }
        }
    }

    private function seedReviews(): void
    {
        $service = app(ReviewService::class);
        $comments = [
            [5, 'Spotless and spacious', 'Very clean apartment, great location near O1 Mall and the host replied within minutes.', true],
            [5, 'Perfect family stay', 'Plenty of room for the kids, the pool was a big hit. Would book again.', true],
            [4, 'Great flat, gate took a while', 'Lovely apartment. Registration at the compound gate took some time on arrival.', true],
            [5, 'Like a hotel suite', 'Beautiful finishing and very comfortable beds.', false],
            [4, 'Good value', 'Comfortable and well equipped. The AC in one room was a bit weak.', true],
            [5, 'Excellent', 'Everything as described. Check-in was easy.', false],
        ];

        $index = 0;
        foreach ($this->bookings as $reservations) {
            $stay = collect($reservations)->first(
                fn (Reservation $r): bool => $r->status === ReservationStatus::CheckedOut && $r->source !== 'direct',
            );

            if ($stay === null || ! isset($comments[$index])) {
                continue;
            }

            [$rating, $title, $comment, $answered] = $comments[$index];

            try {
                $review = $service->import([
                    'direction' => 'guest_to_host',
                    'source' => $stay->source,
                    'external_id' => 'STAYS-SAMPLE-REV-'.(100 + $index),
                    'rating' => $stay->source === 'booking_com' ? $rating * 2 : $rating,
                    'rating_scale' => $stay->source === 'booking_com' ? 10 : 5,
                    'title' => $title,
                    'public_comment' => $comment,
                    'private_comment' => 'Sample review for the demo.',
                    'property_id' => $stay->property_id,
                    'reservation' => $stay,
                    'submitted_at' => $stay->check_out_date->addDays(2),
                    'status' => Review::PUBLISHED,
                ]);

                if ($answered) {
                    $service->respond($review, 'Thank you for staying with us! We hope to welcome you back to Cairo soon.');
                }
            } catch (Throwable $exception) {
                $this->command?->warn('Could not add a sample review: '.$exception->getMessage());
            }

            $index++;
        }
    }

    /** Last month's statement issued and paid; this month's left to build. */
    private function seedStatements(): void
    {
        if ($this->holder === null) {
            return;
        }

        $builder = app(OwnerStatementBuilder::class);
        $payouts = app(OwnerPayoutService::class);
        $lastMonth = $this->today->subMonthNoOverflow()->startOfMonth();

        try {
            $statement = $builder->approve($builder->build($this->holder, $lastMonth, $lastMonth->endOfMonth())->fresh());
            $payout = $payouts->fromStatement($statement->fresh());
            $payouts->markPaid($payout, 'TRF-'.$this->today->format('Ym').'-0001');
        } catch (Throwable $exception) {
            $this->command?->warn('Could not issue last month\'s statement: '.$exception->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function addMissingPhotos(Organization $organization, array $data): void
    {
        $added = app(TenantContext::class)->runAs($organization, fn (): int => DB::transaction(function () use ($data): int {
            $added = 0;

            foreach ($data['properties'] as $definition) {
                $property = Property::query()->where('reference', 'SH-'.$definition['id'])->first();

                if ($property === null || PropertyPhoto::query()->where('property_id', $property->getKey())->exists()) {
                    continue;
                }

                foreach ($definition['photos'] as $position => $url) {
                    PropertyPhoto::query()->create([
                        'organization_id' => $property->organization_id,
                        'property_id' => $property->getKey(),
                        'disk' => 'external',
                        'path' => sprintf('stayshospitality:%d:%d', $definition['id'], $position + 1),
                        'external_url' => $url,
                        'caption' => null,
                        'position' => $position + 1,
                        'is_cover' => $position === 0,
                    ]);

                    $added++;
                }
            }

            return $added;
        }));

        if ($added > 0) {
            $this->command?->info(sprintf('Added %d photos to the Stays Hospitality properties.', $added));
        }
    }

    /** When the sample history starts: properties on sale and owned from here. */
    private function since(): CarbonImmutable
    {
        return $this->today->subMonths(4)->startOfMonth();
    }

    /** @return array<string, mixed> */
    private function data(): array
    {
        return json_decode(
            (string) file_get_contents(__DIR__.'/data/stays-hospitality.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }
}
