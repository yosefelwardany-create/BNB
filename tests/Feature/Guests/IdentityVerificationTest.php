<?php

declare(strict_types=1);

namespace Tests\Feature\Guests;

use App\Domain\Guests\Models\Guest;
use App\Domain\Guests\Services\GuestDirectory;
use App\Domain\Guests\Services\IdentityVerification;
use App\Domain\Organization\Models\Organization;
use App\Domain\Users\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Guest identity verification.
 *
 * `verification_status` was a column with a default that nothing ever changed.
 * These check that it now moves only for a reason — and, more importantly, that
 * it never moves to "verified" on the word of a simulation.
 *
 * That is the load-bearing test in this file. The local verifier can check
 * expiry, shape and age; it cannot confirm a document exists or that the person
 * presenting it is its holder. Marking somebody verified on that basis would be
 * precisely the untruth this product refuses everywhere else, and it would be
 * the one with legal consequences for the operator relying on it.
 */
class IdentityVerificationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $user;

    private Guest $guest;

    protected function setUp(): void
    {
        parent::setUp();

        ['organization' => $this->organization, 'user' => $this->user] = $this->createTenantWithAdmin();

        $this->actingAsUser($this->user, $this->organization);

        $this->guest = app(GuestDirectory::class)->findOrCreate([
            'first_name' => 'Marta',
            'last_name' => 'Silva',
            'email' => 'marta@guests.test',
            'country_code' => 'PT',
        ]);
    }

    private function check(array $overrides = []): TestResponse
    {
        return $this->postJson("/api/v1/guests/{$this->guest->getKey()}/verification", array_merge([
            'document_type' => 'passport',
            'document_number' => 'PA123456',
            'document_expiry' => CarbonImmutable::today()->addYears(4)->toDateString(),
            'date_of_birth' => CarbonImmutable::today()->subYears(34)->toDateString(),
        ], $overrides));
    }

    // ---------------------------------------------------------------------
    // The rule that matters
    // ---------------------------------------------------------------------

    public function test_a_simulated_check_never_marks_a_guest_verified(): void
    {
        $this->check()
            ->assertOk()
            ->assertJsonPath('meta.outcome', 'manual_review')
            ->assertJsonPath('meta.is_simulated', true)
            ->assertJsonPath('meta.requires_a_person', true);

        // Everything checkable passed, and that is still not verification.
        $this->assertSame(IdentityVerification::REVIEW, $this->guest->fresh()->verification_status);
        $this->assertNull($this->guest->fresh()->verified_at);
    }

    public function test_the_response_says_why_nothing_was_really_verified(): void
    {
        $response = $this->check()->assertOk();

        $this->assertNotNull($response->json('meta.simulation_reason'));
        $this->assertStringContainsString(
            'nothing confirms the document exists',
            strtolower((string) $response->json('meta.simulation_reason')),
        );
    }

    // ---------------------------------------------------------------------
    // What the local checks can conclude
    // ---------------------------------------------------------------------

    public function test_an_expired_document_is_rejected(): void
    {
        // The one check that is genuinely conclusive without a provider: an
        // expired document is invalid whoever holds it.
        $this->check(['document_expiry' => CarbonImmutable::today()->subDay()->toDateString()])
            ->assertOk()
            ->assertJsonPath('meta.outcome', 'rejected');

        $this->assertSame(IdentityVerification::REJECTED, $this->guest->fresh()->verification_status);
    }

    public function test_a_holder_below_the_minimum_age_is_rejected(): void
    {
        $this->check(['date_of_birth' => CarbonImmutable::today()->subYears(15)->toDateString()])
            ->assertOk()
            ->assertJsonPath('meta.outcome', 'rejected')
            ->assertJsonPath('meta.reasons.0', fn (string $r): bool => str_contains($r, 'minimum age'));
    }

    public function test_a_malformed_passport_number_is_rejected(): void
    {
        $this->check(['document_number' => 'AB-1'])
            ->assertOk()
            ->assertJsonPath('meta.outcome', 'rejected');
    }

    public function test_an_unknown_document_type_is_rejected(): void
    {
        $this->check(['document_type' => 'library_card'])
            ->assertOk()
            ->assertJsonPath('meta.outcome', 'rejected');
    }

    public function test_a_check_without_a_document_is_refused(): void
    {
        $this->postJson("/api/v1/guests/{$this->guest->getKey()}/verification", [])
            ->assertStatus(422);
    }

    // ---------------------------------------------------------------------
    // A person decides
    // ---------------------------------------------------------------------

    public function test_only_a_person_can_mark_a_guest_verified(): void
    {
        $this->check()->assertOk();

        $this->postJson("/api/v1/guests/{$this->guest->getKey()}/verification/decision", [
            'approved' => true,
            'reason' => 'Passport checked against the original at check-in.',
        ])->assertOk();

        $guest = $this->guest->fresh();

        $this->assertSame(IdentityVerification::VERIFIED, $guest->verification_status);
        $this->assertNotNull($guest->verified_at);

        // And who decided is recorded, because "the system verified them" is not
        // an answer anybody can stand behind when it turns out to be wrong.
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'guest.identity_approved',
            'auditable_id' => $guest->getKey(),
        ]);
    }

    public function test_a_decision_requires_a_reason(): void
    {
        $this->check()->assertOk();

        $this->postJson("/api/v1/guests/{$this->guest->getKey()}/verification/decision", [
            'approved' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('reason');
    }

    public function test_a_person_can_reject_a_document_that_passed_the_local_checks(): void
    {
        $this->check()->assertOk();

        $this->postJson("/api/v1/guests/{$this->guest->getKey()}/verification/decision", [
            'approved' => false,
            'reason' => 'The photograph does not match the person who arrived.',
        ])->assertOk();

        $this->assertSame(IdentityVerification::REJECTED, $this->guest->fresh()->verification_status);
    }

    // ---------------------------------------------------------------------
    // Staying true afterwards
    // ---------------------------------------------------------------------

    public function test_verification_lapses_when_the_document_expires(): void
    {
        $this->check()->assertOk();

        $this->postJson("/api/v1/guests/{$this->guest->getKey()}/verification/decision", [
            'approved' => true,
            'reason' => 'Checked at check-in.',
        ])->assertOk();

        $verification = app(IdentityVerification::class);

        $this->assertTrue($verification->isCurrentlyVerified($this->guest->fresh()));

        // The document lapses. Nothing runs, nobody is told — so the check has
        // to be made at read time or the status quietly outlives the document.
        $this->guest->forceFill([
            'document_expiry' => CarbonImmutable::today()->subDay(),
        ])->save();

        $this->assertFalse($verification->isCurrentlyVerified($this->guest->fresh()));
    }

    public function test_the_document_is_kept_even_when_it_is_rejected(): void
    {
        $this->check(['document_expiry' => CarbonImmutable::today()->subDay()->toDateString()])
            ->assertOk();

        $guest = $this->guest->fresh();

        // A rejection nobody can look at afterwards is a rejection nobody can
        // appeal.
        $this->assertSame('passport', $guest->document_type);
        $this->assertSame('PA123456', $guest->document_number);
    }

    public function test_the_document_number_is_encrypted_at_rest(): void
    {
        $this->check()->assertOk();

        $stored = DB::table('guests')
            ->where('id', $this->guest->getKey())
            ->value('document_number');

        // Readable through the model, never readable in the table.
        $this->assertNotSame('PA123456', $stored);
        $this->assertSame('PA123456', $this->guest->fresh()->document_number);
    }
}
