<?php

declare(strict_types=1);

namespace Tests\Feature\Websites;

use App\Domain\Listings\Models\Listing;
use App\Domain\Organization\Models\Organization;
use App\Domain\Properties\Models\CancellationPolicy;
use App\Domain\Websites\Models\Website;
use App\Domain\Websites\Models\WebsiteCheckout;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The pages a guest books on: the booking box on a property page, the
 * checkout page, and the secret link to their booking.
 *
 * Booking online needs Stripe. Until it is set up a site still takes
 * bookings the old way: the guest is asked to get in touch.
 */
class SiteBookingPagesTest extends TestCase
{
    use BuildsSites;
    use RefreshDatabase;

    private Organization $harbour;

    private Website $site;

    private Listing $flat;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.stripe.key' => null, 'services.stripe.secret' => null]);

        $this->harbour = $this->createOrganization(['name' => 'Harbour Homes', 'base_currency' => 'EUR']);

        $this->site = Website::query()->create([
            'slug' => 'brand',
            'name' => 'Stay With Us',
            'kind' => Website::KIND_BRAND,
            'status' => Website::STATUS_LIVE,
            'settings' => ['contact_email' => 'hello@stay.test', 'contact_phone' => '+351 210 000 000'],
        ]);

        $this->flat = $this->listing($this->harbour, 'Sea View Flat', [
            'cleaning_fee' => 5000,
            'instant_book' => true,
            'house_rules' => 'No parties. Quiet after ten.',
        ]);
        $this->show($this->flat);

        $this->app->make(TenantContext::class)->clear();
    }

    public function test_without_stripe_the_property_page_offers_contact_details(): void
    {
        $this->get('/sites/brand/stay/'.$this->flat->slug)
            ->assertOk()
            ->assertSee('Contact us to book')
            ->assertSee('mailto:hello@stay.test', false)
            ->assertSee('+351 210 000 000')
            ->assertDontSee('data-booking-box', false)
            ->assertDontSee('booking.js', false);
    }

    public function test_with_stripe_the_property_page_carries_the_booking_box(): void
    {
        $this->withStripe();

        $this->get('/sites/brand/stay/'.$this->flat->slug)
            ->assertOk()
            ->assertSee('data-booking-box', false)
            ->assertSee('data-calendar-url="'.$this->site->url('api/listings/'.$this->flat->slug.'/calendar').'"', false)
            ->assertSee('data-quote-url="'.$this->site->url('api/listings/'.$this->flat->slug.'/quote').'"', false)
            ->assertSee('sites/default/booking.js', false)
            ->assertDontSee('Contact us to book');
    }

    public function test_the_checkout_page_shows_the_breakdown_and_policies(): void
    {
        $this->withStripe();
        $this->actingForOrganization($this->harbour);
        $policy = CancellationPolicy::query()->where('slug', 'moderate')->firstOrFail();
        $this->flat->forceFill(['cancellation_policy_id' => $policy->getKey()])->save();
        $this->app->make(TenantContext::class)->clear();

        $checkIn = CarbonImmutable::today()->addDays(30);
        $quote = $this->quote($checkIn, $checkIn->addDays(3));

        $this->get('/sites/brand/checkout/'.$quote['quote_id'])
            ->assertOk()
            ->assertSee('Sea View Flat')
            ->assertSee($checkIn->format('j M Y'))
            ->assertSee($checkIn->addDays(3)->format('j M Y'))
            ->assertSee('3 nights')
            ->assertSee($quote['fees'][0]['label'])
            ->assertSee($quote['totals']['grand_total']['display'])
            ->assertSee($quote['due_now']['display'])
            ->assertSee($policy->name)
            ->assertSee('No parties. Quiet after ten.')
            ->assertSee('name="accept_policies"', false)
            ->assertSee('data-checkout-form', false)
            ->assertSee('value="'.$quote['quote_id'].'"', false)
            ->assertSee('Continue to payment')
            ->assertSee('<meta name="robots" content="noindex">', false);
    }

    public function test_a_request_to_book_checkout_says_the_host_answers_first(): void
    {
        $this->withStripe();
        $barn = $this->listing($this->harbour, 'Old Barn', ['instant_book' => false]);
        $this->show($barn);
        $checkIn = CarbonImmutable::today()->addDays(30);
        $quote = $this->quote($checkIn, $checkIn->addDays(2), $barn);

        $this->get('/sites/brand/checkout/'.$quote['quote_id'])
            ->assertOk()
            ->assertSee('Send request')
            ->assertSee('within 24 hours')
            ->assertDontSee('Continue to payment');
    }

    public function test_an_expired_quote_sends_the_guest_back_to_choose_again(): void
    {
        $this->withStripe();
        $checkIn = CarbonImmutable::today()->addDays(30);
        $quote = $this->quote($checkIn, $checkIn->addDays(3));

        $this->travel(61)->minutes();

        $this->get('/sites/brand/checkout/'.$quote['quote_id'])
            ->assertOk()
            ->assertSee('That price has expired')
            ->assertSee($this->site->url('stay/'.$this->flat->slug).'?checkin='.$checkIn->toDateString(), false)
            ->assertDontSee('data-checkout-form', false);
    }

    public function test_a_quote_for_a_listing_not_on_the_site_has_no_checkout_page_here(): void
    {
        $this->withStripe();
        $checkIn = CarbonImmutable::today()->addDays(30);
        $quote = $this->quote($checkIn, $checkIn->addDays(3));

        $other = Website::query()->create([
            'slug' => 'other',
            'name' => 'Elsewhere',
            'kind' => Website::KIND_BRAND,
            'status' => Website::STATUS_LIVE,
        ]);

        $this->get('/sites/'.$other->slug.'/checkout/'.$quote['quote_id'])->assertNotFound();
        $this->get('/sites/brand/checkout/01JC0000000000000000000000')->assertNotFound();
    }

    public function test_without_stripe_the_checkout_page_offers_contact_details(): void
    {
        $checkIn = CarbonImmutable::today()->addDays(30);
        $quote = $this->quote($checkIn, $checkIn->addDays(3));

        $this->get('/sites/brand/checkout/'.$quote['quote_id'])
            ->assertOk()
            ->assertSee('Contact us to book')
            ->assertDontSee('data-checkout-form', false);
    }

    public function test_the_booking_link_shows_the_hold_and_leaks_nothing(): void
    {
        $this->withStripe();
        $checkIn = CarbonImmutable::today()->addDays(30);
        $quote = $this->quote($checkIn, $checkIn->addDays(3));

        $url = $this->postJson('/sites/brand/api/checkouts', [
            'quote_id' => $quote['quote_id'],
            'first_name' => 'Ana',
            'last_name' => 'Costa',
            'email' => 'ana@example.test',
            'phone' => '+351 912 345 678',
            'accept_policies' => true,
        ])->assertCreated()->json('booking_url');

        $checkout = WebsiteCheckout::query()->sole();

        $response = $this->get($url)
            ->assertOk()
            ->assertSee('Sea View Flat')
            ->assertSee('Ana')
            ->assertSee($quote['totals']['grand_total']['display'])
            ->assertSee('held for you until')
            ->assertSee('<meta name="robots" content="noindex">', false)
            ->assertSee('<meta name="referrer" content="no-referrer">', false);

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $this->get('/sites/brand/booking/'.str_repeat('x', 48))->assertNotFound();

        $checkout->forceFill(['status' => WebsiteCheckout::STATUS_EXPIRED])->save();
        $this->get($url)->assertOk()->assertSee('This hold has lapsed');
    }

    // ------------------------------------------------------------------

    private function withStripe(): void
    {
        $this->fakeStripe();
    }

    /**
     * @return array<string, mixed>
     */
    private function quote(CarbonImmutable $checkIn, CarbonImmutable $checkOut, ?Listing $listing = null): array
    {
        return $this->postJson('/sites/brand/api/listings/'.($listing ?? $this->flat)->slug.'/quote', [
            'check_in' => $checkIn->toDateString(),
            'check_out' => $checkOut->toDateString(),
            'adults' => 2,
        ])->assertOk()->json();
    }
}
