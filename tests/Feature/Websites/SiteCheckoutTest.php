<?php

declare(strict_types=1);

namespace Tests\Feature\Websites;

use App\Domain\Channels\Models\ChannelAccount;
use App\Domain\Channels\Models\ChannelListing;
use App\Domain\Guests\Models\Guest;
use App\Domain\Listings\Models\Listing;
use App\Domain\Organization\Models\Organization;
use App\Domain\Payments\Models\PaymentSchedule;
use App\Domain\Pricing\Models\Quote;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Websites\Models\Website;
use App\Domain\Websites\Models\WebsiteCheckout;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Holding a stay while the guest pays: the nights are taken at once, for a
 * while, and given back if nobody pays.
 */
class SiteCheckoutTest extends TestCase
{
    use BuildsSites;
    use RefreshDatabase;

    private Organization $harbour;

    private Website $site;

    private Listing $flat;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.stripe.key' => 'pk_test_123', 'services.stripe.secret' => 'sk_test_123']);

        $this->harbour = $this->createOrganization(['name' => 'Harbour Homes', 'base_currency' => 'EUR']);

        $this->site = Website::query()->create([
            'slug' => 'brand',
            'name' => 'Stay With Us',
            'kind' => Website::KIND_BRAND,
            'status' => Website::STATUS_LIVE,
            'hold_minutes' => 30,
            'request_hours' => 24,
        ]);

        $this->flat = $this->listing($this->harbour, 'Sea View Flat', ['instant_book' => true]);
        $this->show($this->flat);

        $this->app->make(TenantContext::class)->clear();
    }

    public function test_a_hold_blocks_the_nights_and_marks_the_listing_dirty(): void
    {
        $mapping = $this->channelMapping($this->flat);
        $checkIn = CarbonImmutable::today()->addDays(30);
        $quote = $this->quote($this->flat, $checkIn, $checkIn->addDays(3));

        $response = $this->checkout($quote)->assertCreated()
            ->assertJsonPath('status', WebsiteCheckout::STATUS_HOLDING)
            ->assertJsonPath('mode', 'instant');

        $checkout = WebsiteCheckout::query()->sole();
        $this->assertSame(48, strlen($checkout->access_token));
        $this->assertSame($this->site->url('booking/'.$checkout->access_token), $response->json('booking_url'));
        $this->assertSame($quote, $checkout->quote_id);
        $this->assertSame('Ana', $checkout->guest['first_name']);
        $this->assertNotNull($checkout->guest['accepted_policies_at']);
        $this->assertSame(64, strlen((string) $checkout->client_fingerprint));
        $this->assertEqualsWithDelta(now()->addMinutes(30)->getTimestamp(), $checkout->hold_expires_at->getTimestamp(), 5);

        $reservation = $this->withoutScope(fn () => Reservation::query()->findOrFail($checkout->reservation_id));
        $this->assertSame(ReservationStatus::Tentative, $reservation->status);
        $this->assertSame('direct', $reservation->source);
        $this->assertSame($this->site->getKey(), $reservation->website_id);
        $this->assertSame($checkout->hold_expires_at->getTimestamp(), $reservation->hold_expires_at->getTimestamp());
        $this->assertSame((int) $reservation->grand_total, $checkout->total_amount);

        // The standard plan is in place and its first instalment is what is due now.
        $schedule = $this->withoutScope(fn () => PaymentSchedule::query()->where('reservation_id', $reservation->getKey())->orderBy('sequence')->get());
        $this->assertCount(2, $schedule);
        $this->assertSame((int) $schedule->first()->amount, $checkout->due_now_amount);

        // The quote is spent, and the nights are taken on the site and on every channel.
        $this->assertSame($reservation->getKey(), $this->withoutScope(fn () => Quote::query()->find($quote))->converted_reservation_id);
        $this->assertTrue($this->withoutScope(fn () => $mapping->fresh())->availability_dirty);

        $days = collect($this->getJson('/sites/brand/api/listings/'.$this->flat->slug.'/calendar?month='.$checkIn->format('Y-m'))->json('days'))->keyBy('date');
        $this->assertFalse($days[$checkIn->toDateString()]['available']);
    }

    public function test_two_checkouts_for_the_same_nights_get_one_hold(): void
    {
        $checkIn = CarbonImmutable::today()->addDays(30);
        $first = $this->quote($this->flat, $checkIn, $checkIn->addDays(3));
        $second = $this->quote($this->flat, $checkIn->addDay(), $checkIn->addDays(4));

        $this->checkout($first)->assertCreated();
        $this->checkout($second, ip: '203.0.113.50')
            ->assertStatus(409)
            ->assertJsonPath('message', 'Those dates have just been booked by someone else.');

        $this->assertSame(1, WebsiteCheckout::query()->count());
        $this->assertSame(1, $this->withoutScope(fn () => Reservation::query()->where('status', ReservationStatus::Tentative)->count()));
    }

    public function test_a_fourth_hold_from_one_visitor_is_refused(): void
    {
        $start = CarbonImmutable::today()->addDays(30);

        foreach ([0, 5, 10] as $offset) {
            $this->checkout($this->quote($this->flat, $start->addDays($offset), $start->addDays($offset + 2)))->assertCreated();
        }

        $fourth = $this->quote($this->flat, $start->addDays(15), $start->addDays(17));

        $this->checkout($fourth)
            ->assertStatus(429)
            ->assertJsonPath('message', 'You already have 3 stays waiting for payment. Finish one, or wait for it to lapse, before holding another.');

        // Someone else is not held back by it.
        $this->checkout($fourth, ip: '203.0.113.50')->assertCreated();
        $this->assertSame(4, WebsiteCheckout::query()->count());
    }

    public function test_an_expired_hold_reopens_the_nights(): void
    {
        $checkIn = CarbonImmutable::today()->addDays(30);
        $this->checkout($this->quote($this->flat, $checkIn, $checkIn->addDays(3)))->assertCreated();

        $this->travel(31)->minutes();

        $this->artisan('websites:release-expired-holds')->assertSuccessful();

        $checkout = WebsiteCheckout::query()->sole();
        $this->assertSame(WebsiteCheckout::STATUS_EXPIRED, $checkout->status);
        $this->assertSame(
            ReservationStatus::Cancelled,
            $this->withoutScope(fn () => Reservation::query()->findOrFail($checkout->reservation_id))->status,
        );

        // Somebody else can have the nights now.
        $this->checkout($this->quote($this->flat, $checkIn, $checkIn->addDays(3)), ip: '203.0.113.50')->assertCreated();
    }

    public function test_a_hold_not_yet_due_and_channel_holds_are_left_alone(): void
    {
        $checkIn = CarbonImmutable::today()->addDays(30);
        $this->checkout($this->quote($this->flat, $checkIn, $checkIn->addDays(3)))->assertCreated();

        // A tentative booking from elsewhere carries a hold too; it is not the site's to release.
        $this->book($this->flat, $checkIn->addDays(10), $checkIn->addDays(12));
        $channelHold = $this->withoutScope(fn () => Reservation::query()->where('status', ReservationStatus::Confirmed)->sole());
        DB::table('reservations')->where('id', $channelHold->getKey())->update([
            'status' => ReservationStatus::Tentative->value,
            'hold_expires_at' => now()->subHour(),
        ]);

        $this->travel(10)->minutes();
        $this->artisan('websites:release-expired-holds')->assertSuccessful();

        $this->assertSame(WebsiteCheckout::STATUS_HOLDING, WebsiteCheckout::query()->sole()->status);
        $this->assertSame(ReservationStatus::Tentative, $this->withoutScope(fn () => $channelHold->fresh())->status);
    }

    public function test_a_request_to_book_is_held_only_as_long_as_it_takes_to_pay(): void
    {
        $cottage = $this->listing($this->harbour, 'Old Barn', ['instant_book' => false]);
        $this->show($cottage);
        $checkIn = CarbonImmutable::today()->addDays(30);

        $this->checkout($this->quote($cottage, $checkIn, $checkIn->addDays(2)))
            ->assertCreated()
            ->assertJsonPath('mode', 'request');

        // The host's 24 hours start once the card is given, not before: a
        // guest who leaves at the payment step does not hold the nights a day.
        $this->assertEqualsWithDelta(
            now()->addMinutes(30)->getTimestamp(),
            WebsiteCheckout::query()->sole()->hold_expires_at->getTimestamp(),
            5,
        );
    }

    public function test_no_hold_is_taken_while_the_site_cannot_take_payment(): void
    {
        $checkIn = CarbonImmutable::today()->addDays(30);
        $quote = $this->quote($this->flat, $checkIn, $checkIn->addDays(2));
        config(['services.stripe.key' => null, 'services.stripe.secret' => null]);

        $this->checkout($quote)->assertNotFound();

        $this->assertSame(0, WebsiteCheckout::query()->count());
        $this->assertSame(0, $this->withoutScope(fn () => Reservation::query()->count()));
    }

    public function test_a_new_browser_from_the_same_address_is_the_same_visitor(): void
    {
        $start = CarbonImmutable::today()->addDays(30);

        foreach ([0, 5, 10] as $offset) {
            $this->checkout($this->quote($this->flat, $start->addDays($offset), $start->addDays($offset + 2)), agent: 'Browser '.$offset)->assertCreated();
        }

        $this->checkout($this->quote($this->flat, $start->addDays(15), $start->addDays(17)), agent: 'Yet another browser')->assertStatus(429);
    }

    public function test_addresses_in_one_ipv6_network_are_one_visitor(): void
    {
        $start = CarbonImmutable::today()->addDays(30);

        foreach ([1, 2, 3] as $host) {
            $this->checkout($this->quote($this->flat, $start->addDays($host * 5), $start->addDays($host * 5 + 2)), ip: '2001:db8:1:2::'.$host)->assertCreated();
        }

        $this->checkout($this->quote($this->flat, $start->addDays(30), $start->addDays(32)), ip: '2001:db8:1:2:ffff::9')->assertStatus(429);
        $this->checkout($this->quote($this->flat, $start->addDays(30), $start->addDays(32)), ip: '2001:db8:1:3::1')->assertCreated();
    }

    public function test_a_hold_asked_for_by_another_website_is_refused(): void
    {
        $checkIn = CarbonImmutable::today()->addDays(30);
        $quote = $this->quote($this->flat, $checkIn, $checkIn->addDays(2));

        $this->withHeader('Sec-Fetch-Site', 'cross-site')->checkout($quote)->assertForbidden();
        $this->assertSame(0, WebsiteCheckout::query()->count());

        $this->withHeader('Sec-Fetch-Site', 'same-origin')->checkout($quote)->assertCreated();
    }

    public function test_a_checkout_never_changes_an_existing_guest(): void
    {
        $this->actingForOrganization($this->harbour);
        $known = Guest::query()->create([
            'organization_id' => $this->harbour->getKey(),
            'first_name' => 'Ana',
            'last_name' => 'Real',
            'email' => 'ana@example.test',
        ]);
        $this->app->make(TenantContext::class)->clear();

        $checkIn = CarbonImmutable::today()->addDays(30);
        $this->checkout($this->quote($this->flat, $checkIn, $checkIn->addDays(2)))->assertCreated();

        // Typed by anybody, so it is never taken as the known guest's details.
        $known = $this->withoutScope(fn () => $known->fresh());
        $this->assertNull($known->phone);
        $this->assertSame('Real', $known->last_name);

        $reservation = $this->withoutScope(fn () => Reservation::query()->sole());
        $this->assertNotSame($known->getKey(), $reservation->guest_id);
        $this->assertSame('+351 912 345 678', $this->withoutScope(fn () => Guest::query()->find($reservation->guest_id))->phone);
    }

    public function test_a_released_hold_cancels_its_unpaid_instalments(): void
    {
        $checkIn = CarbonImmutable::today()->addDays(30);
        $this->checkout($this->quote($this->flat, $checkIn, $checkIn->addDays(3)))->assertCreated();

        $this->travel(31)->minutes();
        $this->artisan('websites:release-expired-holds')->assertSuccessful();

        $reservationId = WebsiteCheckout::query()->sole()->reservation_id;
        $statuses = $this->withoutScope(fn () => PaymentSchedule::query()->where('reservation_id', $reservationId)->pluck('status')->unique()->values()->all());

        $this->assertSame(['cancelled'], $statuses);
    }

    public function test_a_last_minute_stay_is_due_in_full_now(): void
    {
        $checkIn = CarbonImmutable::today()->addDays(5);
        $this->checkout($this->quote($this->flat, $checkIn, $checkIn->addDays(2)))->assertCreated();

        $checkout = WebsiteCheckout::query()->sole();
        $this->assertSame($checkout->total_amount, $checkout->due_now_amount);
    }

    public function test_a_checkout_needs_the_guests_details_and_the_policies_accepted(): void
    {
        $checkIn = CarbonImmutable::today()->addDays(30);
        $quote = $this->quote($this->flat, $checkIn, $checkIn->addDays(2));

        $this->postJson('/sites/brand/api/checkouts', ['quote_id' => $quote, 'first_name' => 'Ana', 'email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['last_name', 'email', 'phone', 'accept_policies']);

        $this->assertSame(0, WebsiteCheckout::query()->count());
    }

    public function test_a_spent_expired_or_foreign_quote_cannot_be_held(): void
    {
        $checkIn = CarbonImmutable::today()->addDays(30);

        $elsewhere = $this->listing($this->harbour, 'Not Here');
        $foreign = $this->withoutScope(fn () => Quote::query()->create([
            'organization_id' => $this->harbour->getKey(),
            'listing_id' => $elsewhere->getKey(),
            'property_id' => $elsewhere->property_id,
            'reference' => 'QTE-X1',
            'check_in_date' => $checkIn->toDateString(),
            'check_out_date' => $checkIn->addDays(2)->toDateString(),
            'nights' => 2,
            'adults' => 2,
            'currency' => 'EUR',
            'grand_total' => 20000,
            'breakdown' => [],
            'channel' => 'direct',
            'expires_at' => now()->addHour(),
        ]))->getKey();
        $this->checkout($foreign)->assertNotFound();

        $this->checkout((string) Str::ulid())->assertNotFound();

        $stale = $this->quote($this->flat, $checkIn, $checkIn->addDays(2));
        $this->travel(61)->minutes();
        $this->checkout($stale)
            ->assertStatus(409)
            ->assertJsonPath('message', 'That price has expired. Check the dates again for a fresh one.');
        $this->travelBack();

        $spent = $this->quote($this->flat, $checkIn->addDays(5), $checkIn->addDays(7));
        $this->checkout($spent)->assertCreated();
        $this->checkout($spent, ip: '203.0.113.50')->assertStatus(409);
    }

    public function test_a_changed_price_is_not_held_at_the_old_one(): void
    {
        $checkIn = CarbonImmutable::today()->addDays(30);
        $quote = $this->quote($this->flat, $checkIn, $checkIn->addDays(3));

        DB::table('properties')->where('id', $this->flat->property_id)->update(['base_rate' => 15000]);

        $this->checkout($quote)
            ->assertStatus(409)
            ->assertJsonPath('message', 'The price for these dates has just changed. Check the dates again for the new one.');

        $this->assertSame(0, WebsiteCheckout::query()->count());
        $this->assertSame(0, $this->withoutScope(fn () => Reservation::query()->count()));
    }

    // ------------------------------------------------------------------

    private function quote(Listing $listing, CarbonImmutable $checkIn, CarbonImmutable $checkOut): string
    {
        return (string) $this->postJson('/sites/brand/api/listings/'.$listing->slug.'/quote', [
            'check_in' => $checkIn->toDateString(),
            'check_out' => $checkOut->toDateString(),
            'adults' => 2,
        ])->assertOk()->json('quote_id');
    }

    private function checkout(string $quoteId, string $ip = '203.0.113.9', string $agent = 'Test browser'): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip, 'HTTP_USER_AGENT' => $agent])
            ->postJson('/sites/brand/api/checkouts', [
                'quote_id' => $quoteId,
                'first_name' => 'Ana',
                'last_name' => 'Costa',
                'email' => 'ana@example.test',
                'phone' => '+351 912 345 678',
                'message' => 'We arrive late.',
                'accept_policies' => true,
            ]);
    }

    private function channelMapping(Listing $listing): ChannelListing
    {
        $this->actingForOrganization($this->harbour);

        $account = ChannelAccount::query()->create([
            'organization_id' => $this->harbour->getKey(),
            'channel' => 'airbnb',
            'name' => 'Airbnb',
            'status' => ChannelAccount::STATUS_CONNECTED,
            'sync_availability' => true,
            'sync_rates' => true,
        ]);

        $mapping = ChannelListing::query()->create([
            'organization_id' => $this->harbour->getKey(),
            'channel_account_id' => $account->getKey(),
            'listing_id' => $listing->getKey(),
            'property_id' => $listing->property_id,
            'external_listing_id' => 'ext-1',
        ]);

        $this->app->make(TenantContext::class)->clear();

        return $mapping;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $read
     * @return T
     */
    private function withoutScope(callable $read): mixed
    {
        return $this->app->make(TenantContext::class)->withoutScope($read);
    }
}
