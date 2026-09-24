<?php

declare(strict_types=1);

namespace Tests\Feature\Users;

use App\Domain\Organization\Models\Organization;
use App\Domain\Platform\Models\Plan;
use App\Domain\Platform\Services\PlatformSettings;
use App\Domain\Platform\Support\PlanFeature;
use App\Domain\Users\Models\User;
use App\Domain\Users\Services\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Two-factor authentication.
 *
 * What these protect:
 *
 *  - A correct password with MFA on issues **nothing** — no session, no token,
 *    only a reference. The most common way to get this wrong is to hand out a
 *    token that "only works for the MFA endpoint", and a token is a token.
 *  - A challenge is single-use and bounded. Six digits is a million
 *    possibilities if guessing is limited and nothing at all if it is not.
 *  - Recovery codes work once each.
 *  - Neither enforcement point can lock somebody out with no way back: the
 *    enrolment endpoints stay reachable, and the requirement is a deliberate
 *    setting rather than a default.
 */
class MfaTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $user;

    private TotpService $totp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->totp = $this->app->make(TotpService::class);

        ['organization' => $this->organization, 'user' => $this->user] = $this->createTenantWithAdmin();

        // The helper's password, needed for every write on the MFA endpoints.
        $this->user->forceFill(['password' => Hash::make('password-for-tests-1234')])->save();
    }

    private function code(?string $secret = null): string
    {
        $secret ??= $this->user->fresh()->mfa_secret;

        return $this->totp->at((string) $secret, intdiv(time(), 30));
    }

    /**
     * Walk the whole enrolment, returning the recovery codes.
     *
     * @return list<string>
     */
    private function enrol(): array
    {
        $this->actingAsUser($this->user, $this->organization);

        $begin = $this->postJson('/api/v1/auth/mfa/begin', [
            'password' => 'password-for-tests-1234',
        ])->assertOk();

        $secret = $begin->json('data.secret');

        $confirm = $this->postJson('/api/v1/auth/mfa/confirm', [
            'code' => $this->totp->at($secret, intdiv(time(), 30)),
        ])->assertOk();

        return $confirm->json('data.recovery_codes');
    }

    // ---------------------------------------------------------------------
    // Enrolment
    // ---------------------------------------------------------------------

    public function test_enrolment_requires_the_password(): void
    {
        $this->actingAsUser($this->user, $this->organization)
            ->postJson('/api/v1/auth/mfa/begin', ['password' => 'wrong'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_beginning_enrolment_changes_nothing_until_it_is_confirmed(): void
    {
        $this->actingAsUser($this->user, $this->organization)
            ->postJson('/api/v1/auth/mfa/begin', ['password' => 'password-for-tests-1234'])
            ->assertOk()
            ->assertJsonStructure(['data' => ['secret', 'uri']]);

        // An abandoned enrolment must leave the account exactly as it was.
        // Enabling first is how somebody locks themselves out with a mistyped QR.
        $this->assertFalse((bool) $this->user->fresh()->mfa_enabled);

        $this->postJson('/api/v1/auth/logout')->assertOk();

        $this->postJson('/api/v1/auth/login', [
            'email' => $this->user->email,
            'password' => 'password-for-tests-1234',
        ])->assertOk()->assertJsonMissing(['mfa_required' => true]);
    }

    public function test_a_wrong_code_does_not_complete_enrolment(): void
    {
        $this->actingAsUser($this->user, $this->organization)
            ->postJson('/api/v1/auth/mfa/begin', ['password' => 'password-for-tests-1234'])
            ->assertOk();

        $this->postJson('/api/v1/auth/mfa/confirm', ['code' => '000000'])
            ->assertStatus(422);

        $this->assertFalse((bool) $this->user->fresh()->mfa_enabled);
    }

    public function test_enrolment_issues_ten_single_use_recovery_codes(): void
    {
        $codes = $this->enrol();

        $this->assertCount(10, $codes);

        // Hashes only. A recovery code readable from the database is a password
        // stored in clear.
        foreach ($this->user->fresh()->mfa_recovery_codes as $stored) {
            $this->assertNotContains($stored, $codes);
            $this->assertTrue(str_starts_with($stored, '$2y$'));
        }

        $this->getJson('/api/v1/auth/mfa')
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.recovery_codes_remaining', 10);
    }

    // ---------------------------------------------------------------------
    // The challenge
    // ---------------------------------------------------------------------

    public function test_a_correct_password_alone_issues_no_session_and_no_token(): void
    {
        $this->enrol();
        $this->postJson('/api/v1/auth/logout')->assertOk();
        $this->app['auth']->forgetGuards();

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $this->user->email,
            'password' => 'password-for-tests-1234',
            'device_name' => 'laptop',
        ])->assertOk()->assertJsonPath('mfa_required', true);

        // The load-bearing assertion of this whole feature.
        $this->assertNull($response->json('token'));
        $this->assertNull($response->json('user'));
        $this->assertIsString($response->json('challenge.reference'));

        // And the reference authorises nothing on its own.
        $this->withToken($response->json('challenge.reference'))
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();
    }

    public function test_the_challenge_completes_the_sign_in_and_issues_a_token(): void
    {
        $this->enrol();
        $secret = $this->user->fresh()->mfa_secret;
        $this->postJson('/api/v1/auth/logout')->assertOk();
        $this->app['auth']->forgetGuards();

        $reference = $this->postJson('/api/v1/auth/login', [
            'email' => $this->user->email,
            'password' => 'password-for-tests-1234',
        ])->json('challenge.reference');

        $response = $this->postJson('/api/v1/auth/mfa/challenge', [
            'challenge' => $reference,
            'code' => $this->code($secret),
            'device_name' => 'laptop',
        ])->assertOk();

        $this->assertIsString($response->json('token'));
        $this->assertSame($this->user->email, $response->json('user.email'));

        $this->app['auth']->forgetGuards();

        $this->withToken($response->json('token'))
            ->withHeader('X-Organization', $this->organization->getKey())
            ->getJson('/api/v1/auth/me')
            ->assertOk();
    }

    public function test_a_challenge_cannot_be_used_twice(): void
    {
        $this->enrol();
        $secret = $this->user->fresh()->mfa_secret;
        $this->postJson('/api/v1/auth/logout')->assertOk();
        $this->app['auth']->forgetGuards();

        $reference = $this->postJson('/api/v1/auth/login', [
            'email' => $this->user->email,
            'password' => 'password-for-tests-1234',
        ])->json('challenge.reference');

        $code = $this->code($secret);

        $this->postJson('/api/v1/auth/mfa/challenge', [
            'challenge' => $reference,
            'code' => $code,
        ])->assertOk();

        // Replaying an intercepted reference with a code still inside its window
        // must fail.
        $this->postJson('/api/v1/auth/mfa/challenge', [
            'challenge' => $reference,
            'code' => $code,
        ])->assertStatus(422);
    }

    public function test_five_wrong_codes_end_the_attempt(): void
    {
        $this->enrol();
        $this->postJson('/api/v1/auth/logout')->assertOk();
        $this->app['auth']->forgetGuards();

        $reference = $this->postJson('/api/v1/auth/login', [
            'email' => $this->user->email,
            'password' => 'password-for-tests-1234',
        ])->json('challenge.reference');

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $this->postJson('/api/v1/auth/mfa/challenge', [
                'challenge' => $reference,
                'code' => '000000',
            ])->assertStatus(422);
        }

        // The fifth ends it rather than leaving it open to a sixth.
        $this->postJson('/api/v1/auth/mfa/challenge', [
            'challenge' => $reference,
            'code' => '000000',
        ])->assertStatus(422);

        $this->postJson('/api/v1/auth/mfa/challenge', [
            'challenge' => $reference,
            'code' => '000000',
        ])->assertStatus(422)->assertJsonPath('message', fn (string $m): bool => str_contains($m, 'expired'));
    }

    public function test_an_unknown_challenge_is_refused(): void
    {
        $this->postJson('/api/v1/auth/mfa/challenge', [
            'challenge' => 'made-up',
            'code' => '123456',
        ])->assertStatus(422);
    }

    // ---------------------------------------------------------------------
    // Recovery codes
    // ---------------------------------------------------------------------

    public function test_a_recovery_code_signs_in_once_and_is_then_spent(): void
    {
        $codes = $this->enrol();
        $this->postJson('/api/v1/auth/logout')->assertOk();
        $this->app['auth']->forgetGuards();

        $reference = $this->postJson('/api/v1/auth/login', [
            'email' => $this->user->email,
            'password' => 'password-for-tests-1234',
        ])->json('challenge.reference');

        $this->postJson('/api/v1/auth/mfa/challenge', [
            'challenge' => $reference,
            'code' => $codes[0],
        ])->assertOk();

        $this->assertCount(9, $this->user->fresh()->mfa_recovery_codes);

        // The same code again must not work.
        $this->postJson('/api/v1/auth/logout');
        $this->app['auth']->forgetGuards();

        $second = $this->postJson('/api/v1/auth/login', [
            'email' => $this->user->email,
            'password' => 'password-for-tests-1234',
        ])->json('challenge.reference');

        $this->postJson('/api/v1/auth/mfa/challenge', [
            'challenge' => $second,
            'code' => $codes[0],
        ])->assertStatus(422);
    }

    public function test_regenerating_recovery_codes_invalidates_the_old_ones(): void
    {
        $original = $this->enrol();

        $fresh = $this->postJson('/api/v1/auth/mfa/recovery-codes', [
            'password' => 'password-for-tests-1234',
        ])->assertOk()->json('data.recovery_codes');

        $this->assertCount(10, $fresh);
        $this->assertNotSame($original, $fresh);

        $this->postJson('/api/v1/auth/logout');
        $this->app['auth']->forgetGuards();

        $reference = $this->postJson('/api/v1/auth/login', [
            'email' => $this->user->email,
            'password' => 'password-for-tests-1234',
        ])->json('challenge.reference');

        $this->postJson('/api/v1/auth/mfa/challenge', [
            'challenge' => $reference,
            'code' => $original[0],
        ])->assertStatus(422);
    }

    // ---------------------------------------------------------------------
    // Turning it off
    // ---------------------------------------------------------------------

    public function test_disabling_needs_the_password_and_a_code(): void
    {
        $this->enrol();
        $secret = $this->user->fresh()->mfa_secret;

        // A stolen password must not be enough to remove the thing protecting
        // against a stolen password.
        $this->deleteJson('/api/v1/auth/mfa', [
            'password' => 'password-for-tests-1234',
            'code' => '000000',
        ])->assertStatus(422);

        $this->assertTrue((bool) $this->user->fresh()->mfa_enabled);

        $this->deleteJson('/api/v1/auth/mfa', [
            'password' => 'password-for-tests-1234',
            'code' => $this->code($secret),
        ])->assertOk();

        $this->assertFalse((bool) $this->user->fresh()->mfa_enabled);
        $this->assertNull($this->user->fresh()->mfa_secret);
    }

    // ---------------------------------------------------------------------
    // Enforcement, and not locking anybody out
    // ---------------------------------------------------------------------

    public function test_an_organization_can_require_it_of_its_members(): void
    {
        $plan = Plan::query()->create([
            'name' => 'Secure',
            'slug' => 'secure-'.uniqid(),
            'features' => [PlanFeature::REQUIRE_MFA],
        ]);

        $this->organization->forceFill([
            'plan_id' => $plan->getKey(),
            'settings' => ['security' => ['require_mfa' => true]],
        ])->save();

        $this->actingAsUser($this->user, $this->organization->fresh());

        $this->getJson('/api/v1/properties')->assertForbidden();

        // And the way out stays open: enrolment is reachable even while every
        // tenant route is refused.
        $this->postJson('/api/v1/auth/mfa/begin', ['password' => 'password-for-tests-1234'])
            ->assertOk();
    }

    public function test_the_requirement_needs_both_the_plan_feature_and_the_setting(): void
    {
        // An organization on no plan is allowed every feature, so treating the
        // feature alone as the requirement would impose MFA on every customer of
        // a fresh install. The setting is what decides.
        $this->actingAsUser($this->user, $this->organization)
            ->getJson('/api/v1/properties')
            ->assertOk();

        $this->organization->forceFill([
            'settings' => ['security' => ['require_mfa' => true]],
        ])->save();

        // Now the setting is on but there is no plan granting the feature...
        // an unmetered organization is allowed everything, so this does apply.
        $this->actingAsUser($this->user, $this->organization->fresh())
            ->getJson('/api/v1/properties')
            ->assertForbidden();
    }

    public function test_the_platform_console_can_require_it_but_does_not_by_default(): void
    {
        $operator = User::query()->create([
            'first_name' => 'Platform',
            'last_name' => 'Operator',
            'email' => 'operator-mfa@platform.test',
            'password' => 'password-for-tests-1234',
            'status' => 'active',
        ]);

        $operator->forceFill(['is_platform_admin' => true, 'email_verified_at' => now()])->save();

        // Off by default, and that is deliberate: a fresh install's first
        // administrator has no second factor, and with this on they could never
        // reach the console to switch it off.
        $this->actingAs($operator, 'sanctum')
            ->getJson('/api/v1/platform/overview')
            ->assertOk();

        app(PlatformSettings::class)->put(['require_mfa_for_platform_admins' => true]);

        $this->actingAs($operator->fresh(), 'sanctum')
            ->getJson('/api/v1/platform/overview')
            ->assertForbidden();
    }
}
