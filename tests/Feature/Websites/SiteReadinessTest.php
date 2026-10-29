<?php

declare(strict_types=1);

namespace Tests\Feature\Websites;

use App\Domain\Listings\Models\Listing;
use App\Domain\Organization\Models\Organization;
use App\Domain\Properties\Models\Property;
use App\Domain\Users\Models\User;
use App\Domain\Websites\Models\Website;
use App\Domain\Websites\Support\SitePayments;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * What a site still needs before it can take bookings online, as the
 * Websites screen shows it.
 *
 * Only what would leave the site with nothing to show stops it being
 * published: without payments a live site still shows its homes and asks
 * guests to get in touch, as it was built to.
 */
class SiteReadinessTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private Organization $harbour;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operator = $this->createPlatformAdmin();
        $this->harbour = $this->createOrganization(['name' => 'Harbour Homes']);

        config([
            'services.stripe.key' => null,
            'services.stripe.secret' => null,
            'services.stripe.webhook_secret' => null,
            'mail.default' => 'log',
            'filesystems.disks.media.driver' => 'local',
        ]);
    }

    public function test_readiness_names_what_is_missing(): void
    {
        $site = $this->site(withListing: true);

        $lines = collect($this->asOperator()->getJson("/api/v1/platform/websites/{$site->getKey()}")
            ->assertOk()
            ->json('data.readiness'))->keyBy('key');

        $this->assertSame(['properties', 'payments', 'email', 'contact', 'images'], $lines->keys()->all());
        $this->assertTrue($lines['properties']['ok']);
        $this->assertFalse($lines['payments']['ok']);
        $this->assertStringContainsString('STRIPE_SECRET_KEY', $lines['payments']['message']);
        // Each service reads its own settings: the scheduler releases holds
        // and charges balances, the worker sends the email.
        $this->assertStringContainsString('habitat-api, habitat-worker and habitat-scheduler', $lines['payments']['message']);
        $this->assertFalse($lines['email']['ok']);
        $this->assertStringContainsString('MAIL_MAILER', $lines['email']['message']);
        $this->assertStringContainsString('habitat-api and habitat-worker', $lines['email']['message']);
        $this->assertFalse($lines['contact']['ok']);
        $this->assertStringContainsString('not emailed to anyone', $lines['contact']['message']);
        $this->assertFalse($lines['images']['ok']);
        $this->assertStringContainsString('R2', $lines['images']['message']);
        // Only an empty site is stopped from going live.
        $this->assertTrue($lines['properties']['blocks_publishing']);
        $this->assertFalse($lines['payments']['blocks_publishing']);
    }

    public function test_readiness_is_green_once_everything_is_set_up(): void
    {
        config([
            'services.stripe.key' => 'pk_live_x',
            'services.stripe.secret' => 'sk_live_x',
            'services.stripe.webhook_secret' => 'whsec_x',
            'mail.default' => 'smtp',
            'filesystems.disks.media.driver' => 's3',
        ]);

        $site = $this->site(withListing: true);
        $site->forceFill(['settings' => ['contact_email' => 'hello@stay.test']])->save();

        $lines = $this->asOperator()->getJson("/api/v1/platform/websites/{$site->getKey()}")->json('data.readiness');

        $this->assertSame([true, true, true, true, true], array_column($lines, 'ok'));
        $this->assertSame('New bookings and requests are emailed to hello@stay.test.', collect($lines)->firstWhere('key', 'contact')['message']);
    }

    public function test_payments_need_the_webhook_secret_too(): void
    {
        config(['services.stripe.key' => 'pk_live_x', 'services.stripe.secret' => 'sk_live_x']);
        $site = $this->site(withListing: true);

        $payments = collect($this->asOperator()->getJson("/api/v1/platform/websites/{$site->getKey()}")->json('data.readiness'))
            ->firstWhere('key', 'payments');

        // Without it no payment is ever confirmed, so booking online stays off.
        $this->assertFalse($payments['ok']);
        $this->assertStringContainsString('STRIPE_WEBHOOK_SECRET', $payments['message']);
    }

    public function test_a_site_with_nothing_bookable_cannot_go_live(): void
    {
        $site = $this->site(withListing: false);

        $this->asOperator()
            ->postJson("/api/v1/platform/websites/{$site->getKey()}/publish")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Add at least one property before publishing.');
    }

    public function test_a_site_goes_live_without_payments_and_asks_guests_to_get_in_touch(): void
    {
        $site = $this->site(withListing: true);

        $this->asOperator()
            ->postJson("/api/v1/platform/websites/{$site->getKey()}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', 'live');
    }

    public function test_without_email_guests_are_asked_to_get_in_touch_rather_than_book_online(): void
    {
        config([
            'services.stripe.key' => 'pk_live_x',
            'services.stripe.secret' => 'sk_live_x',
            'services.stripe.webhook_secret' => 'whsec_x',
            'mail.default' => 'log',
        ]);
        $site = $this->site(withListing: true);

        // Taking money with nobody told of it — no confirmation for the guest,
        // no request for the host to answer — is worse than not taking it.
        $this->assertFalse(SitePayments::online());

        $payments = collect($this->asOperator()->getJson("/api/v1/platform/websites/{$site->getKey()}")->json('data.readiness'))
            ->firstWhere('key', 'payments');
        $this->assertFalse($payments['ok']);
        $this->assertStringContainsString('until emails are sent', $payments['message']);

        config(['mail.default' => 'smtp']);
        $this->assertTrue(SitePayments::online());
    }

    public function test_media_disk_r2_keeps_images_in_cloudflare_with_public_links(): void
    {
        $_ENV['MEDIA_DISK'] = $_SERVER['MEDIA_DISK'] = 'r2';
        $_ENV['R2_PUBLIC_URL'] = $_SERVER['R2_PUBLIC_URL'] = 'https://media.stay.test';

        try {
            $disks = (require config_path('filesystems.php'))['disks'];
        } finally {
            unset($_ENV['MEDIA_DISK'], $_SERVER['MEDIA_DISK'], $_ENV['R2_PUBLIC_URL'], $_SERVER['R2_PUBLIC_URL']);
        }

        $this->assertSame('s3', $disks['media']['driver']);

        $disk = Storage::build(array_merge($disks['media'], [
            'key' => 'key', 'secret' => 'secret', 'bucket' => 'habitat', 'endpoint' => 'https://account.r2.cloudflarestorage.com',
        ]));

        $this->assertSame('https://media.stay.test/websites/x/logo.png', $disk->url('websites/x/logo.png'));
    }

    // ------------------------------------------------------------------

    private function site(bool $withListing): Website
    {
        $site = Website::query()->create([
            'slug' => 'brand',
            'name' => 'Stay With Us',
            'kind' => Website::KIND_BRAND,
            'status' => Website::STATUS_DRAFT,
        ]);

        if ($withListing) {
            $this->actingForOrganization($this->harbour);
            $property = Property::factory()->active()->create(['organization_id' => $this->harbour->getKey()]);
            $listing = Listing::factory()->published()->create([
                'organization_id' => $this->harbour->getKey(),
                'property_id' => $property->getKey(),
                'title' => 'Sea View Flat',
            ]);
            $this->app->make(TenantContext::class)->clear();

            $site->listings()->create([
                'listing_id' => $listing->getKey(),
                'organization_id' => $this->harbour->getKey(),
                'position' => 0,
            ]);
        }

        return $site;
    }

    private function asOperator(): static
    {
        return $this->actingAs($this->operator->fresh(), 'sanctum');
    }
}
