<?php

declare(strict_types=1);

namespace Tests\Feature\Users;

use App\Domain\Organization\Enums\OrganizationStatus;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Services\OrganizationProvisioner;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What a new account gets.
 *
 * There are no plans, caps, trials or feature gates any more: the business is a
 * managed service billing a commission on what properties earn, not a product
 * selling seats. A new organization is simply active.
 */
class RegistrationAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_company_is_active_with_no_countdown(): void
    {
        $organization = $this->register();

        $this->assertSame(OrganizationStatus::Active, $organization->status);
        $this->assertNull($organization->trial_ends_at);
    }

    public function test_an_explicit_status_still_wins(): void
    {
        // The seeder and account administration both set this deliberately,
        // and a default must not quietly override a decision somebody made.
        $organization = $this->register(['status' => 'suspended']);

        $this->assertSame(OrganizationStatus::Suspended, $organization->status);
    }

    public function test_formerly_gated_features_are_reachable_with_no_plan(): void
    {
        ['organization' => $organization, 'user' => $admin] = $this->createTenantWithAdmin();

        // Every route that used to carry a `feature:` gate answers on its own
        // permission alone. A legacy plan row on the organization changes
        // nothing, because nothing reads it.
        $organization->forceFill(['plan_id' => null, 'feature_overrides' => ['channels' => false]])->save();

        $this->actingAsUser($admin, $organization->fresh());

        $this->getJson('/api/v1/channels')->assertOk();
        $this->getJson('/api/v1/automation/rules')->assertOk();
        $this->getJson('/api/v1/upsells')->assertOk();
        $this->getJson('/api/v1/locks')->assertOk();
        $this->getJson('/api/v1/api-keys')->assertOk();
        $this->getJson('/api/v1/webhook-endpoints')->assertOk();
    }

    public function test_the_old_subscription_endpoints_are_gone(): void
    {
        ['organization' => $organization, 'user' => $admin] = $this->createTenantWithAdmin();

        $this->actingAsUser($admin, $organization);

        $this->getJson('/api/v1/organization/plan')->assertNotFound();
        $this->getJson('/api/v1/organization/announcements')->assertNotFound();
        $this->getJson('/api/v1/organization/support-sessions')->assertNotFound();
    }

    /**
     * A company created the way registration creates one.
     *
     * Deliberately not the test-case helper, which passes an explicit
     * `status: active` of its own — that would satisfy these assertions without
     * the policy under test ever being consulted.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function register(array $attributes = []): Organization
    {
        $organization = $this->app->make(OrganizationProvisioner::class)
            ->createOrganization($attributes + ['name' => 'Fresh Signup']);

        $this->app->make(TenantContext::class)->clear();

        return $organization->fresh();
    }
}
