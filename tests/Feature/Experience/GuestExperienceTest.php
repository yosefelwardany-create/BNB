<?php

declare(strict_types=1);

namespace Tests\Feature\Experience;

use App\Domain\Listings\Models\Listing;
use App\Domain\Locks\Models\AccessCode;
use App\Domain\Locks\Models\SmartLock;
use App\Domain\Locks\Services\AccessCodeManager;
use App\Domain\Organization\Models\Organization;
use App\Domain\Properties\Models\Property;
use App\Domain\Reservations\DataObjects\ReservationRequest;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Reservations\Services\ReservationService;
use App\Domain\Reviews\Models\Review;
use App\Domain\Reviews\Services\ReviewService;
use App\Domain\Upsells\Exceptions\UpsellUnavailableException;
use App\Domain\Upsells\Models\UpsellOrder;
use App\Domain\Upsells\Models\UpsellProduct;
use App\Domain\Upsells\Services\UpsellService;
use App\Domain\Users\Models\User;
use App\Domain\Users\Support\RoleRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Reviews, extras and door access.
 *
 * The common thread: each of these is a record about a guest that outlives the
 * stay, and each has a rule about what may be changed afterwards. A review is
 * not ours to edit, an accepted order is not ours to reprice, and a code is
 * never reported as issued until a lock has accepted it.
 */
class GuestExperienceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Property $property;

    private Listing $listing;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->createOrganization(['base_currency' => 'EUR']);

        $this->property = Property::factory()->active()->create([
            'organization_id' => $this->organization->getKey(),
            'currency' => 'EUR',
            'base_rate' => 10000,
            'cleaning_fee' => 5000,
            'max_occupancy' => 4,
            'timezone' => 'Europe/Lisbon',
        ]);

        $this->listing = Listing::factory()->published()->create([
            'organization_id' => $this->organization->getKey(),
            'property_id' => $this->property->getKey(),
            'currency' => 'EUR',
            'minimum_nights' => 1,
        ]);

        $this->admin = $this->createUser($this->organization, [RoleRegistry::ORGANIZATION_ADMIN]);
    }

    // ------------------------------------------------------------------
    // Reviews
    // ------------------------------------------------------------------

    public function test_ratings_from_different_scales_are_comparable(): void
    {
        $reviews = $this->app->make(ReviewService::class);

        // Nine out of ten and nine out of five are not the same review, and a
        // raw average of them means nothing.
        $outOfTen = $reviews->import([
            'property_id' => $this->property->getKey(),
            'source' => 'booking_com',
            'external_id' => 'bc-1',
            'rating' => 9,
            'rating_scale' => 10,
        ]);

        $outOfFive = $reviews->import([
            'property_id' => $this->property->getKey(),
            'source' => 'airbnb',
            'external_id' => 'ab-1',
            'rating' => 4,
            'rating_scale' => 5,
        ]);

        $this->assertSame(90, $outOfTen->rating_percent);
        $this->assertSame(80, $outOfFive->rating_percent);

        $summary = $reviews->summary($this->property->getKey());

        $this->assertEqualsWithDelta(85.0, $summary['average_percent'], 0.01);
        $this->assertEqualsWithDelta(4.25, $summary['average_out_of_five'], 0.01);
    }

    public function test_a_redelivered_review_does_not_count_twice(): void
    {
        $reviews = $this->app->make(ReviewService::class);

        $attributes = [
            'property_id' => $this->property->getKey(),
            'source' => 'airbnb',
            'external_id' => 'ab-dup',
            'rating' => 5,
            'rating_scale' => 5,
            'public_comment' => 'Lovely flat.',
        ];

        $first = $reviews->import($attributes);
        $second = $reviews->import($attributes);

        // Channels redeliver. A review counted twice would halve a property's
        // average for no reason.
        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(1, Review::query()->count());
    }

    public function test_an_amended_review_is_refreshed_but_the_response_survives(): void
    {
        $reviews = $this->app->make(ReviewService::class);

        $review = $reviews->import([
            'property_id' => $this->property->getKey(),
            'source' => 'airbnb',
            'external_id' => 'ab-2',
            'rating' => 3,
            'rating_scale' => 5,
            'public_comment' => 'The heating was broken.',
        ]);

        $reviews->respond($review, 'We have replaced the boiler. Thank you for telling us.');

        // The guest edits theirs on the channel; we re-import.
        $reviews->import([
            'property_id' => $this->property->getKey(),
            'source' => 'airbnb',
            'external_id' => 'ab-2',
            'rating' => 4,
            'rating_scale' => 5,
            'public_comment' => 'The heating was broken but they fixed it quickly.',
        ]);

        $fresh = $review->fresh();

        $this->assertSame(4, $fresh->rating);
        $this->assertStringContainsString('fixed it quickly', $fresh->public_comment);

        // The response is ours and is never overwritten by an import.
        $this->assertStringContainsString('replaced the boiler', $fresh->response);
    }

    public function test_a_review_cannot_be_edited_through_the_api(): void
    {
        $review = $this->app->make(ReviewService::class)->import([
            'property_id' => $this->property->getKey(),
            'source' => 'airbnb',
            'external_id' => 'ab-3',
            'rating' => 2,
            'rating_scale' => 5,
            'public_comment' => 'Noisy street.',
        ]);

        // There is no update route at all. Editing a guest's words would be
        // fabrication, and the copy on the channel would disagree anyway.
        $this->actingAsUser($this->admin, $this->organization)
            ->patchJson("/api/v1/reviews/{$review->getKey()}", ['public_comment' => 'Lovely and quiet.'])
            ->assertStatus(405);
    }

    public function test_hiding_a_review_is_named_for_what_it_does(): void
    {
        $review = $this->app->make(ReviewService::class)->import([
            'property_id' => $this->property->getKey(),
            'source' => 'airbnb',
            'external_id' => 'ab-4',
            'rating' => 1,
            'rating_scale' => 5,
            'public_comment' => 'Abusive content.',
        ]);

        $response = $this->actingAsUser($this->admin, $this->organization)
            ->postJson("/api/v1/reviews/{$review->getKey()}/hide", ['reason' => 'Abusive.'])
            ->assertOk();

        $this->assertTrue($response->json('data.is_hidden_internally'));

        // Suppressed here, untouched there. It is still in the database and
        // still public on the channel.
        $this->assertDatabaseHas('reviews', ['id' => $review->getKey()]);

        $this->actingAsUser($this->admin, $this->organization)
            ->getJson('/api/v1/reviews')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_the_response_queue_shows_what_still_needs_answering(): void
    {
        $reviews = $this->app->make(ReviewService::class);

        $answered = $reviews->import([
            'property_id' => $this->property->getKey(),
            'source' => 'airbnb', 'external_id' => 'ab-5', 'rating' => 5, 'rating_scale' => 5,
        ]);

        $reviews->respond($answered, 'Thank you.');

        $reviews->import([
            'property_id' => $this->property->getKey(),
            'source' => 'airbnb', 'external_id' => 'ab-6', 'rating' => 2, 'rating_scale' => 5,
        ]);

        $response = $this->actingAsUser($this->admin, $this->organization)
            ->getJson('/api/v1/reviews?awaiting_response=1')
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('ab-6', Review::query()->find($response->json('data.0.id'))->external_id);
    }

    // ------------------------------------------------------------------
    // Upsells
    // ------------------------------------------------------------------

    public function test_an_automatic_extra_is_charged_and_scheduled_at_once(): void
    {
        $reservation = $this->book(10, 13);

        $product = $this->product([
            'name' => 'Extra mid-stay clean',
            'code' => 'MIDCLEAN',
            'kind' => 'extra_cleaning',
            'price' => 6000,
            'requires_approval' => false,
        ]);

        $before = (int) $reservation->grand_total;

        $order = $this->app->make(UpsellService::class)->order($reservation, $product);

        $this->assertSame(UpsellOrder::APPROVED, $order->status);

        // Charged: the booking total went up by the price.
        $this->assertSame($before + 6000, (int) $reservation->fresh()->grand_total);
        $this->assertNotNull($order->reservation_charge_id);

        // ...and scheduled. An upsell with a charge and no task is an upsell
        // nobody delivers.
        $this->assertNotNull($order->task_id);
    }

    public function test_an_extra_cannot_be_sold_inside_its_lead_time(): void
    {
        // Arriving tomorrow; the product needs three days' notice.
        $reservation = $this->book(1, 4);

        $product = $this->product([
            'name' => 'Airport transfer',
            'code' => 'TRANSFER',
            'kind' => 'transfer',
            'price' => 4000,
            'lead_time_hours' => 72,
        ]);

        $this->expectException(UpsellUnavailableException::class);
        $this->expectExceptionMessage('72 hour');

        $this->app->make(UpsellService::class)->order($reservation, $product);
    }

    public function test_the_menu_hides_what_cannot_be_delivered_in_time(): void
    {
        $reservation = $this->book(1, 4);

        $this->product([
            'name' => 'Airport transfer', 'code' => 'TRANSFER',
            'price' => 4000, 'lead_time_hours' => 72,
        ]);

        $this->product([
            'name' => 'Welcome hamper', 'code' => 'HAMPER',
            'price' => 2500, 'lead_time_hours' => 2,
        ]);

        $menu = $this->app->make(UpsellService::class)->availableFor($reservation);

        // A menu that offers what it cannot provide is worse than a shorter
        // menu.
        $this->assertCount(1, $menu);
        $this->assertSame('HAMPER', $menu[0]['code']);
    }

    public function test_a_finite_service_cannot_be_oversold_for_one_day(): void
    {
        $product = $this->product([
            'name' => 'Airport transfer', 'code' => 'TRANSFER',
            'price' => 4000, 'daily_capacity' => 1,
        ]);

        $upsells = $this->app->make(UpsellService::class);

        $first = $this->book(20, 23);
        $second = $this->book(40, 43);

        $serviceDate = CarbonImmutable::today()->addDays(20)->toDateString();

        $upsells->order($first, $product, 1, ['service_date' => $serviceDate]);

        // One van, one day. Selling it twice is a guest left at an airport.
        $this->expectException(UpsellUnavailableException::class);
        $this->expectExceptionMessage('fully booked');

        $upsells->order($second, $product, 1, ['service_date' => $serviceDate]);
    }

    public function test_the_price_is_fixed_at_the_moment_of_ordering(): void
    {
        $reservation = $this->book(20, 23);

        $product = $this->product([
            'name' => 'Airport transfer', 'code' => 'TRANSFER', 'price' => 4000,
        ]);

        $order = $this->app->make(UpsellService::class)->order($reservation, $product);

        $product->forceFill(['price' => 9000])->save();

        // A guest who ordered a transfer at 40 pays 40, whatever it costs by
        // the time somebody drives them.
        $this->assertSame(4000, (int) $order->fresh()->total_price);
    }

    public function test_a_per_night_extra_multiplies_by_the_stay(): void
    {
        $reservation = $this->book(20, 25);   // five nights

        $product = $this->product([
            'name' => 'Parking', 'code' => 'PARKING',
            'price' => 1000, 'charge_basis' => UpsellProduct::PER_NIGHT,
        ]);

        $order = $this->app->make(UpsellService::class)->order($reservation, $product);

        // The single field that decides whether parking is 10 or 50.
        $this->assertSame(5000, (int) $order->total_price);
    }

    public function test_cancelling_an_order_takes_the_charge_off_the_booking(): void
    {
        $reservation = $this->book(20, 23);

        $product = $this->product(['name' => 'Hamper', 'code' => 'HAMPER', 'price' => 2500]);

        $before = (int) $reservation->grand_total;

        $order = $this->app->make(UpsellService::class)->order($reservation, $product);

        $this->assertSame($before + 2500, (int) $reservation->fresh()->grand_total);

        $this->app->make(UpsellService::class)->cancel($order, 'Guest changed their mind.');

        // An extra that never happened should not appear on the bill at all.
        $this->assertSame($before, (int) $reservation->fresh()->grand_total);
        $this->assertSame(UpsellOrder::CANCELLED, $order->fresh()->status);
        $this->assertNull($order->fresh()->reservation_charge_id);
    }

    public function test_an_order_needing_approval_waits_for_it(): void
    {
        $reservation = $this->book(20, 23);

        $product = $this->product([
            'name' => 'Late checkout', 'code' => 'LATEOUT',
            'price' => 3000, 'requires_approval' => true,
        ]);

        $before = (int) $reservation->grand_total;

        $order = $this->app->make(UpsellService::class)->order($reservation, $product);

        $this->assertSame(UpsellOrder::REQUESTED, $order->status);

        // Nothing is charged until somebody agrees.
        $this->assertSame($before, (int) $reservation->fresh()->grand_total);

        $this->actingAsUser($this->admin, $this->organization)
            ->postJson("/api/v1/upsells/orders/{$order->getKey()}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', UpsellOrder::APPROVED);

        $this->assertSame($before + 3000, (int) $reservation->fresh()->grand_total);
    }

    // ------------------------------------------------------------------
    // Locks
    // ------------------------------------------------------------------

    public function test_a_code_against_a_simulated_lock_says_so(): void
    {
        $reservation = $this->book(2, 5);
        $lock = $this->lock();

        $codes = $this->app->make(AccessCodeManager::class)->issueForReservation($reservation);

        $this->assertCount(1, $codes);

        $code = $codes[0];

        $this->assertSame(AccessCode::ACTIVE, $code->status);

        // The bundled provider is a real local implementation, not a live one.
        // A code issued against it opens nothing, and the record says so.
        $this->assertTrue($code->is_simulated);
        $this->assertFalse($code->opensARealDoor());
    }

    public function test_the_code_is_encrypted_at_rest(): void
    {
        $reservation = $this->book(2, 5);
        $this->lock();

        $code = $this->app->make(AccessCodeManager::class)->issueForReservation($reservation)[0];

        $plain = $code->code;

        $this->assertMatchesRegularExpression('/^\d{6}$/', $plain);

        // A database disclosure should not be a set of keys to every door in
        // the portfolio.
        $raw = DB::table('access_codes')->where('id', $code->getKey())->value('code');

        $this->assertStringNotContainsString($plain, (string) $raw);

        // The last digits are in clear, which identifies a code without
        // opening anything.
        $this->assertSame(substr($plain, -4), $code->code_last4);
    }

    public function test_the_access_window_is_padded_in_the_properties_own_time(): void
    {
        $reservation = $this->book(2, 5);
        $lock = $this->lock();

        [$from, $until] = $this->app->make(AccessCodeManager::class)
            ->windowFor($reservation, $lock);

        // Check-in is 15:00 Lisbon; the code starts an hour before, because a
        // guest whose taxi was early should not stand outside until three
        // exactly.
        $this->assertSame('14:00', $from->setTimezone('Europe/Lisbon')->format('H:i'));
        $this->assertSame('12:00', $until->setTimezone('Europe/Lisbon')->format('H:i'));
    }

    public function test_a_lock_that_refuses_a_code_never_reports_it_as_issued(): void
    {
        $reservation = $this->book(2, 5);

        // A lock the provider has never heard of.
        $lock = SmartLock::query()->create([
            'organization_id' => $this->organization->getKey(),
            'property_id' => $this->property->getKey(),
            'name' => 'Ghost lock',
            'provider' => 'mock',
            'connection_id' => 'conn-1',
            'external_lock_id' => 'does-not-exist',
        ]);

        $code = $this->app->make(AccessCodeManager::class)->issue(
            $lock,
            [CarbonImmutable::now(), CarbonImmutable::now()->addDay()],
        );

        // Recorded as failed, never as active. A guest sent a code the lock
        // refused is a guest standing outside at midnight.
        $this->assertSame(AccessCode::FAILED, $code->status);
        $this->assertNotNull($code->last_error);
        $this->assertFalse($code->isUsable());
    }

    public function test_cancelling_a_booking_withdraws_its_codes(): void
    {
        $reservation = $this->book(2, 5);
        $this->lock();

        $manager = $this->app->make(AccessCodeManager::class);
        $manager->issueForReservation($reservation);

        $revoked = $manager->revokeForReservation($reservation, 'Cancelled.');

        $this->assertSame(1, $revoked);

        $code = AccessCode::query()->where('reservation_id', $reservation->getKey())->first();

        $this->assertSame(AccessCode::REVOKED, $code->status);
        $this->assertNotNull($code->revoked_at);
    }

    public function test_expired_codes_are_retired_so_the_lock_has_free_slots(): void
    {
        $reservation = $this->book(2, 5);
        $this->lock();

        $code = $this->app->make(AccessCodeManager::class)->issueForReservation($reservation)[0];

        // The stay is over. Both ends move, because the database refuses a
        // window that ends before it begins — which is itself the guarantee
        // that no code can be issued for a period that never existed.
        $code->forceFill([
            'valid_from' => CarbonImmutable::now()->subDays(2),
            'valid_until' => CarbonImmutable::now()->subHour(),
        ])->save();

        $retired = $this->app->make(AccessCodeManager::class)->retireExpired();

        // A lock has a finite number of code slots, and an expired code still
        // counted as active is why the next guest's cannot be issued.
        $this->assertSame(1, $retired);
        $this->assertSame(AccessCode::EXPIRED, $code->fresh()->status);
    }

    public function test_a_listing_of_codes_shows_four_digits_and_not_the_code(): void
    {
        $reservation = $this->book(2, 5);
        $this->lock();

        $code = $this->app->make(AccessCodeManager::class)->issueForReservation($reservation)[0];

        $response = $this->actingAsUser($this->admin, $this->organization)
            ->getJson('/api/v1/locks/codes')
            ->assertOk();

        // A list of working door codes is a set of keys.
        $this->assertStringNotContainsString($code->code, $response->getContent());
        $this->assertSame($code->code_last4, $response->json('data.0.code_last4'));

        // ...and it is revealed to somebody who may operate the lock and has
        // asked for it.
        $this->actingAsUser($this->admin, $this->organization)
            ->getJson("/api/v1/locks/codes/{$code->getKey()}/reveal")
            ->assertOk()
            ->assertJsonPath('data.code', $code->code);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function product(array $attributes): UpsellProduct
    {
        return UpsellProduct::query()->create(array_merge([
            'organization_id' => $this->organization->getKey(),
            'currency' => 'EUR',
            'kind' => 'other',
        ], $attributes));
    }

    private function lock(): SmartLock
    {
        // A lock the bundled provider actually knows about, so the issuing
        // path is exercised rather than short-circuited.
        DB::table('simulated_locks')->insert([
            'id' => (string) Str::ulid(),
            'connection_id' => 'conn-1',
            'external_lock_id' => 'lock-1',
            'name' => 'Front door',
            'online' => true,
            'locked' => true,
            'battery_percent' => 90,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return SmartLock::query()->create([
            'organization_id' => $this->organization->getKey(),
            'property_id' => $this->property->getKey(),
            'name' => 'Front door',
            'provider' => 'mock',
            'connection_id' => 'conn-1',
            'external_lock_id' => 'lock-1',
            'is_simulated' => true,
        ]);
    }

    private function book(int $inDays, int $outDays): Reservation
    {
        return $this->app->make(ReservationService::class)->create(new ReservationRequest(
            listing: $this->listing,
            checkIn: CarbonImmutable::today()->addDays($inDays),
            checkOut: CarbonImmutable::today()->addDays($outDays),
            adults: 2,
            status: ReservationStatus::Confirmed,
            guestAttributes: [
                'first_name' => 'Ana',
                'last_name' => 'Costa',
                'email' => 'guest-'.uniqid().'@example.test',
            ],
            bookedAt: CarbonImmutable::today(),
        ));
    }
}
