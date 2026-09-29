<?php

declare(strict_types=1);

namespace Tests\Feature\Users;

use App\Domain\Organization\Enums\OrganizationStatus;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Services\OrganizationProvisioner;
use App\Domain\Platform\Support\PlanFeature;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * What somebody gets when they sign up.
 *
 * Every feature and no caps, which is what a null plan already resolved to —
 * `allows()` returns true and every limit is null. The part that was wrong was
 * what the interface said about it: a 30-day trial countdown on an account whose
 * access was unlimited and whose expiry nothing enforced. Telling somebody their
 * access is about to end when it is not is the same class of mistake as telling
 * them a message was sent when it was not.
 */
class RegistrationAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_company_is_active_with_no_countdown(): void
    {
        $organization = $this->register();

        $this->assertSame(OrganizationStatus::Active, $organization->status);
        $this->assertNull($organization->trial_ends_at);
        $this->assertFalse($organization->trialHasExpired());
    }

    public function test_a_new_company_has_every_feature(): void
    {
        $organization = $this->register();

        foreach (PlanFeature::all() as $feature) {
            $this->assertTrue(
                $organization->allows($feature),
                sprintf('A new company should have %s.', $feature),
            );
        }
    }

    public function test_a_new_company_has_no_caps(): void
    {
        $organization = $this->register();

        foreach ($organization->effectiveLimits() as $key => $limit) {
            $this->assertNull($limit, sprintf('%s should be uncapped for a new company.', $key));
        }
    }

    public function test_the_policy_can_be_put_back_without_touching_code(): void
    {
        // The point of reading it from configuration: when there is something to
        // sell, this is the whole change.
        config()->set('pms.registration.status', 'trial');
        config()->set('pms.registration.trial_days', 14);

        $organization = $this->register();

        $this->assertSame(OrganizationStatus::Trial, $organization->status);
        $this->assertNotNull($organization->trial_ends_at);
        $this->assertSame(14, (int) round(now()->diffInDays($organization->trial_ends_at)));
    }

    public function test_an_explicit_status_still_wins(): void
    {
        // The seeder and the platform console both set this deliberately, and a
        // default must not quietly override a decision somebody made.
        $organization = $this->register(['status' => 'suspended']);

        $this->assertSame(OrganizationStatus::Suspended, $organization->status);
    }

    public function test_companies_already_on_a_countdown_can_be_lifted_off_it(): void
    {
        config()->set('pms.registration.status', 'trial');
        config()->set('pms.registration.trial_days', 30);

        $onTrial = $this->register(['name' => 'Signed up last month']);

        config()->set('pms.registration.status', 'active');
        config()->set('pms.registration.trial_days', null);

        $suspended = $this->register(['name' => 'Suspended', 'status' => 'suspended']);

        Artisan::call('organizations:lift-trials');

        $this->assertSame(OrganizationStatus::Active, $onTrial->fresh()->status);
        $this->assertNull($onTrial->fresh()->trial_ends_at);

        // Narrow on purpose: a command that edits billing status must not
        // quietly reinstate an account somebody suspended.
        $this->assertSame(OrganizationStatus::Suspended, $suspended->fresh()->status);
    }

    public function test_the_dry_run_changes_nothing(): void
    {
        config()->set('pms.registration.status', 'trial');
        config()->set('pms.registration.trial_days', 30);

        $organization = $this->register();

        Artisan::call('organizations:lift-trials', ['--dry-run' => true]);

        $this->assertSame(OrganizationStatus::Trial, $organization->fresh()->status);
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
