<?php

declare(strict_types=1);

namespace Tests\Feature\Users;

use App\Domain\Organization\Models\Organization;
use App\Domain\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Changing your own name, email address and password.
 *
 * There was no way to do either from inside the product: the only route to a
 * new password was a reset link by email, which is no use at all on a
 * deployment with no mail provider, and no route to a new email address at
 * any time.
 *
 * The threat these protect against is not a stolen password, it is a borrowed
 * laptop with a live session. So the current password is required to change
 * either the email or the password, and changing the password ends every other
 * session — a password is changed because it might be known, and leaving the
 * sessions it protected alive would make the change ceremonial.
 */
class ProfileTest extends TestCase
{
    use RefreshDatabase;

    private const CURRENT = 'current-password-1234';

    private Organization $organization;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        ['organization' => $this->organization, 'user' => $this->user] = $this->createTenantWithAdmin();

        $this->user->forceFill(['password' => self::CURRENT])->save();

        $this->actingAsUser($this->user->fresh(), $this->organization);
    }

    // -----------------------------------------------------------------------
    // Details
    // -----------------------------------------------------------------------

    public function test_ordinary_details_change_without_a_password(): void
    {
        // Nothing about a display name is a security boundary, and demanding a
        // password to fix a typo teaches people to type it without thinking.
        $this->patchJson('/api/v1/auth/profile', [
            'first_name' => 'Osama',
            'phone' => '+351 912 345 678',
        ])->assertOk()->assertJsonPath('data.first_name', 'Osama');

        $this->assertSame('Osama', $this->user->fresh()->first_name);
    }

    public function test_changing_the_email_requires_the_current_password(): void
    {
        // The takeover move: point somebody's account at your own address
        // while their screen is unlocked, then reset the password at leisure.
        $this->patchJson('/api/v1/auth/profile', ['email' => 'attacker@example.test'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('current_password');

        $this->assertNotSame('attacker@example.test', $this->user->fresh()->email);
    }

    public function test_the_email_changes_when_the_password_is_given(): void
    {
        $this->patchJson('/api/v1/auth/profile', [
            'email' => 'osama@example.test',
            'current_password' => self::CURRENT,
        ])->assertOk()->assertJsonPath('data.email', 'osama@example.test');

        $fresh = $this->user->fresh();

        $this->assertSame('osama@example.test', $fresh->email);

        // Nobody has proved the new address belongs to them, so it is recorded
        // as unverified rather than inheriting the old address's standing.
        $this->assertNull($fresh->email_verified_at);
    }

    public function test_a_taken_email_is_refused_without_confirming_who_holds_it(): void
    {
        User::query()->create([
            'first_name' => 'Someone',
            'last_name' => 'Else',
            'email' => 'taken@example.test',
            'password' => 'another-password-1234',
            'status' => 'active',
        ]);

        $response = $this->patchJson('/api/v1/auth/profile', [
            'email' => 'taken@example.test',
            'current_password' => self::CURRENT,
        ])->assertStatus(422);

        // "Already taken" would turn this endpoint into a way to discover who
        // holds an account here.
        $this->assertStringNotContainsStringIgnoringCase(
            'already',
            (string) $response->json('errors.email.0'),
        );
    }

    public function test_the_change_is_recorded(): void
    {
        $this->patchJson('/api/v1/auth/profile', [
            'email' => 'osama@example.test',
            'current_password' => self::CURRENT,
        ])->assertOk();

        // Where an account's address went is the first question asked after a
        // takeover, and the only useful answer is a record of it.
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'user.email_changed',
            'auditable_id' => $this->user->getKey(),
        ]);
    }

    // -----------------------------------------------------------------------
    // Password
    // -----------------------------------------------------------------------

    public function test_the_password_changes(): void
    {
        $this->postJson('/api/v1/auth/password', [
            'current_password' => self::CURRENT,
            'password' => 'a-brand-new-password-99',
            'password_confirmation' => 'a-brand-new-password-99',
        ])->assertOk();

        $this->assertTrue(Hash::check('a-brand-new-password-99', (string) $this->user->fresh()->password));
    }

    public function test_it_refuses_without_the_current_password(): void
    {
        $this->postJson('/api/v1/auth/password', [
            'current_password' => 'not-the-right-one',
            'password' => 'a-brand-new-password-99',
            'password_confirmation' => 'a-brand-new-password-99',
        ])->assertStatus(422)->assertJsonValidationErrors('current_password');

        $this->assertTrue(Hash::check(self::CURRENT, (string) $this->user->fresh()->password));
    }

    public function test_a_weak_password_is_refused_here_exactly_as_at_sign_up(): void
    {
        // Twelve characters, letters and numbers, and not in a breach list. A
        // settings screen holding a lower bar than registration would be a
        // hole in the shape of convenience.
        foreach (['123456789', 'short1', 'passwordpassword'] as $weak) {
            $this->postJson('/api/v1/auth/password', [
                'current_password' => self::CURRENT,
                'password' => $weak,
                'password_confirmation' => $weak,
            ])->assertStatus(422)->assertJsonValidationErrors('password');
        }

        $this->assertTrue(Hash::check(self::CURRENT, (string) $this->user->fresh()->password));
    }

    public function test_reusing_the_current_password_is_refused(): void
    {
        $this->postJson('/api/v1/auth/password', [
            'current_password' => self::CURRENT,
            'password' => self::CURRENT,
            'password_confirmation' => self::CURRENT,
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_changing_the_password_ends_every_other_session(): void
    {
        $stale = $this->user->createToken('another-device')->plainTextToken;

        $this->postJson('/api/v1/auth/password', [
            'current_password' => self::CURRENT,
            'password' => 'a-brand-new-password-99',
            'password_confirmation' => 'a-brand-new-password-99',
        ])->assertOk()->assertJsonPath('meta.other_sessions_ended', true);

        $this->app['auth']->forgetGuards();

        // The point of the change: the phone left on a train is signed out.
        $this->withHeader('Authorization', 'Bearer '.$stale)
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();
    }

    public function test_the_session_doing_the_change_survives_it(): void
    {
        $token = $this->user->createToken('this-device')->plainTextToken;

        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/auth/password', [
                'current_password' => self::CURRENT,
                'password' => 'a-brand-new-password-99',
                'password_confirmation' => 'a-brand-new-password-99',
            ])->assertOk();

        $this->app['auth']->forgetGuards();

        // Signing somebody out of the screen they are looking at, as a reward
        // for good security hygiene, is how people stop doing it.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/auth/me')
            ->assertOk();
    }

    // -----------------------------------------------------------------------
    // Who can reach it
    // -----------------------------------------------------------------------

    public function test_a_platform_administrator_can_change_their_own_password(): void
    {
        $operator = User::query()->create([
            'first_name' => 'Platform',
            'last_name' => 'Operator',
            'email' => 'operator@platform.test',
            'password' => self::CURRENT,
            'status' => 'active',
        ]);

        $operator->forceFill(['is_platform_admin' => true, 'email_verified_at' => now()])->save();

        $this->app['auth']->forgetGuards();
        $this->actingAs($operator->fresh(), 'sanctum');

        // They hold no membership anywhere, so a tenant-scoped route would
        // leave the account that governs the platform unable to rotate its own
        // password.
        $this->postJson('/api/v1/auth/password', [
            'current_password' => self::CURRENT,
            'password' => 'a-brand-new-password-99',
            'password_confirmation' => 'a-brand-new-password-99',
        ])->assertOk();
    }

    public function test_it_refuses_an_unauthenticated_caller(): void
    {
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/v1/auth/password', [
            'current_password' => self::CURRENT,
            'password' => 'a-brand-new-password-99',
            'password_confirmation' => 'a-brand-new-password-99',
        ])->assertUnauthorized();
    }
}
