<?php

declare(strict_types=1);

namespace Tests\Feature\Websites;

use App\Domain\Listings\Models\Listing;
use App\Domain\Organization\Models\Organization;
use App\Domain\Websites\Models\Website;
use App\Domain\Websites\Models\WebsiteCheckout;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The pages a site's pieces load on another website: the search bar, the
 * homes grid and a home's booking box, and the pop-up's pages. Framed by the
 * site's store addresses and by nothing else, never indexed, and drawn
 * without the site's header and footer.
 */
class SiteEmbedTest extends TestCase
{
    use BuildsSites;
    use RefreshDatabase;

    private const STORES = ['https://shop.example.com', 'https://www.example.com'];

    private Organization $harbour;

    private Website $site;

    private Listing $flat;

    private Listing $loft;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeStripe();

        $this->harbour = $this->createOrganization(['name' => 'Harbour Homes', 'base_currency' => 'EUR']);

        $this->site = Website::query()->create([
            'slug' => 'brand',
            'name' => 'Stay With Us',
            'kind' => Website::KIND_BRAND,
            'status' => Website::STATUS_LIVE,
            'hold_minutes' => 30,
            'request_hours' => 24,
            'embed_origins' => self::STORES,
        ]);

        $this->flat = $this->listing($this->harbour, 'Sea View Flat', ['instant_book' => true]);
        $this->show($this->flat);
        $this->loft = $this->listing($this->harbour, 'Harbour Loft', ['instant_book' => true]);
        $this->show($this->loft);
        $this->site->listings()->where('listing_id', $this->loft->getKey())->update(['store_url' => 'https://shop.example.com/products/loft']);

        $this->app->make(TenantContext::class)->clear();
    }

    public function test_each_piece_and_popup_page_is_framed_only_by_the_store_addresses(): void
    {
        $checkIn = CarbonImmutable::today()->addDays(30);
        $quote = $this->quote($this->flat, $checkIn, $checkIn->addDays(3));
        $this->checkout($quote)->assertCreated();
        $token = WebsiteCheckout::query()->sole()->access_token;
        $other = $this->quote($this->flat, $checkIn->addDays(10), $checkIn->addDays(12));

        foreach ([
            'embed/search',
            'embed/homes',
            'embed/results',
            'embed/book/'.$this->flat->slug,
            'embed/stay/'.$this->flat->slug,
            'embed/checkout/'.$other,
            'embed/booking/'.$token,
        ] as $path) {
            $this->get('/sites/brand/'.$path)
                ->assertOk()
                ->assertHeader('Content-Security-Policy', 'frame-ancestors https://shop.example.com https://www.example.com')
                ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        }
    }

    public function test_a_site_without_store_addresses_cannot_be_framed(): void
    {
        $this->site->forceFill(['embed_origins' => null])->save();

        $this->get('/sites/brand/embed/homes')
            ->assertOk()
            ->assertHeader('Content-Security-Policy', "frame-ancestors 'none'");
    }

    public function test_the_hosted_pages_send_no_frame_policy(): void
    {
        $this->get('/sites/brand')->assertOk()->assertHeaderMissing('Content-Security-Policy');
        $this->get('/sites/brand/stay/'.$this->flat->slug)->assertOk()->assertHeaderMissing('Content-Security-Policy');
    }

    public function test_pieces_have_no_site_header_or_footer(): void
    {
        $this->get('/sites/brand/embed/homes')
            ->assertOk()
            ->assertDontSee('site-header', false)
            ->assertDontSee('site-footer', false)
            ->assertSee('data-embed="piece"', false)
            ->assertSee('data-allowed="'.e(json_encode(self::STORES)).'"', false);

        $this->get('/sites/brand/embed/stay/'.$this->flat->slug)
            ->assertOk()
            ->assertDontSee('site-header', false)
            ->assertSee('data-embed="popup"', false);
    }

    public function test_a_card_opens_the_popup_or_goes_to_the_homes_store_page(): void
    {
        $checkIn = CarbonImmutable::today()->addDays(30)->toDateString();
        $checkOut = CarbonImmutable::today()->addDays(33)->toDateString();

        $page = $this->get('/sites/brand/embed/homes?checkin='.$checkIn.'&checkout='.$checkOut)->assertOk();

        $page->assertSee('href="https://shop.example.com/products/loft?checkin='.$checkIn.'&amp;checkout='.$checkOut.'"', false);
        $page->assertSee('href="'.url('/sites/brand/embed/stay/'.$this->flat->slug).'?checkin='.$checkIn.'&amp;checkout='.$checkOut.'"', false);
        $this->assertSame(1, substr_count($page->getContent(), 'data-habitat-go'));
        $this->assertSame(1, substr_count($page->getContent(), 'data-habitat-open'));
    }

    public function test_a_piece_for_a_home_not_on_the_site_is_not_found(): void
    {
        $this->get('/sites/brand/embed/book/not-here')->assertNotFound();

        $this->site->forceFill(['status' => Website::STATUS_DRAFT])->save();
        $this->get('/sites/brand/embed/homes')->assertNotFound();
    }

    public function test_the_booking_box_in_a_frame_goes_on_to_checkout_in_the_frame(): void
    {
        $this->get('/sites/brand/embed/book/'.$this->flat->slug)
            ->assertOk()
            ->assertSee('data-checkout-url="'.url('/sites/brand/embed/checkout').'"', false);
    }

    public function test_a_checkout_started_in_a_frame_continues_in_the_frame(): void
    {
        $checkIn = CarbonImmutable::today()->addDays(30);

        $url = $this->checkout($this->quote($this->flat, $checkIn, $checkIn->addDays(3)), embed: true)
            ->assertCreated()
            ->json('booking_url');

        $this->assertSame(url('/sites/brand/embed/booking/'.WebsiteCheckout::query()->sole()->access_token), $url);
    }

    public function test_a_checkout_started_on_the_site_continues_on_the_site(): void
    {
        $checkIn = CarbonImmutable::today()->addDays(30);

        $url = $this->checkout($this->quote($this->flat, $checkIn, $checkIn->addDays(3)))
            ->assertCreated()
            ->json('booking_url');

        $this->assertSame(url('/sites/brand/booking/'.WebsiteCheckout::query()->sole()->access_token), $url);
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

    private function checkout(string $quoteId, bool $embed = false): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9', 'HTTP_USER_AGENT' => 'Test browser'])
            ->postJson('/sites/brand/api/checkouts', [
                'quote_id' => $quoteId,
                'first_name' => 'Ana',
                'last_name' => 'Costa',
                'email' => 'ana@example.test',
                'phone' => '+351 912 345 678',
                'accept_policies' => true,
            ] + ($embed ? ['embed' => true] : []));
    }
}
