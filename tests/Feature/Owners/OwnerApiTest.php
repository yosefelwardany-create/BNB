<?php

declare(strict_types=1);

namespace Tests\Feature\Owners;

use App\Domain\Organization\Models\Organization;
use App\Domain\Owners\Models\Owner;
use App\Domain\Owners\Services\OwnershipLedger;
use App\Domain\Properties\Models\Property;
use App\Domain\Users\Models\User;
use App\Domain\Users\Support\RoleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The owner API over HTTP.
 *
 * What matters here beyond the CRUD: bank details must never come back in
 * full, and the roles that can read an owner must not thereby be able to read
 * where their money goes.
 */
class OwnerApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Property $property;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->createOrganization(['base_currency' => 'EUR']);

        $this->property = Property::factory()->active()->create([
            'organization_id' => $this->organization->getKey(),
            'currency' => 'EUR',
        ]);

        $this->admin = $this->createUser($this->organization, [RoleRegistry::ORGANIZATION_ADMIN]);
    }

    public function test_an_owner_can_be_created_with_banking_details(): void
    {
        $response = $this->actingAsUser($this->admin, $this->organization)
            ->postJson('/api/v1/owners', [
                'type' => 'individual',
                'first_name' => 'Ana',
                'last_name' => 'Ferreira',
                'email' => 'ana@example.test',
                'payout_currency' => 'EUR',
                'bank_iban' => 'PT50000201231234567890154',
                'bank_name' => 'Banco Exemplo',
                'statement_frequency' => 'monthly',
                'statement_day' => 5,
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.display_name', 'Ana Ferreira')
            ->assertJsonPath('data.payout_currency', 'EUR');

        // Masked on the way out, always — even for the administrator who just
        // typed it in.
        $iban = $response->json('data.banking.iban');

        $this->assertNotNull($iban);
        $this->assertStringNotContainsString('000201231234567890', (string) $iban);
        $this->assertStringEndsWith('0154', (string) $iban);

        // And stored encrypted rather than in the clear.
        $raw = DB::table('owners')
            ->where('id', $response->json('data.id'))
            ->value('bank_iban');

        $this->assertStringNotContainsString('PT50', (string) $raw);
    }

    public function test_a_role_that_can_read_owners_cannot_read_their_banking(): void
    {
        $owner = Owner::query()->create([
            'organization_id' => $this->organization->getKey(),
            'first_name' => 'Ana',
            'last_name' => 'Ferreira',
            'bank_iban' => 'PT50000201231234567890154',
        ]);

        // A reservations agent can see owners — they answer the phone — but
        // where an owner's money goes is not their business.
        $agent = $this->createUser($this->organization, [RoleRegistry::RESERVATIONS_AGENT]);

        $response = $this->actingAsUser($agent, $this->organization)
            ->getJson("/api/v1/owners/{$owner->getKey()}");

        if ($response->status() === 200) {
            $this->assertNull($response->json('data.banking'));
        } else {
            $response->assertForbidden();
        }
    }

    public function test_an_update_that_omits_banking_leaves_it_alone(): void
    {
        $create = $this->actingAsUser($this->admin, $this->organization)
            ->postJson('/api/v1/owners', [
                'first_name' => 'Ana',
                'email' => 'ana-'.uniqid().'@example.test',
                'bank_iban' => 'PT50000201231234567890154',
            ]);

        $id = $create->json('data.id');

        $this->actingAsUser($this->admin, $this->organization)
            ->patchJson("/api/v1/owners/{$id}", ['city' => 'Porto'])
            ->assertOk();

        $this->assertSame(
            'PT50000201231234567890154',
            Owner::query()->find($id)->bank_iban,
        );
    }

    public function test_posting_a_masked_value_back_does_not_overwrite_the_real_one(): void
    {
        $create = $this->actingAsUser($this->admin, $this->organization)
            ->postJson('/api/v1/owners', [
                'first_name' => 'Ana',
                'email' => 'ana-'.uniqid().'@example.test',
                'bank_iban' => 'PT50000201231234567890154',
            ]);

        $id = $create->json('data.id');
        $masked = $create->json('data.banking.iban');

        // A settings form that renders the mask and posts the whole record
        // back would otherwise store the bullets as the account number, and
        // nobody finds out until a payout fails.
        $this->actingAsUser($this->admin, $this->organization)
            ->patchJson("/api/v1/owners/{$id}", ['bank_iban' => $masked])
            ->assertOk();

        $this->assertSame(
            'PT50000201231234567890154',
            Owner::query()->find($id)->bank_iban,
        );
    }

    public function test_an_over_allocated_share_comes_back_with_the_offending_dates(): void
    {
        $ledger = $this->app->make(OwnershipLedger::class);

        $first = Owner::query()->create([
            'organization_id' => $this->organization->getKey(),
            'first_name' => 'First',
        ]);

        $ledger->assign($this->property, $first, ['ownership_percentage' => 70]);

        $second = Owner::query()->create([
            'organization_id' => $this->organization->getKey(),
            'first_name' => 'Second',
        ]);

        $response = $this->actingAsUser($this->admin, $this->organization)
            ->postJson("/api/v1/owners/{$second->getKey()}/ownerships", [
                'property_id' => $this->property->getKey(),
                'ownership_percentage' => 40,
            ]);

        $response->assertStatus(422);

        // Which day is over-allocated, and by how much — not "that did not
        // work".
        $this->assertNotEmpty($response->json('conflicts'));
        $this->assertEqualsWithDelta(110.0, $response->json('conflicts.0.total'), 0.0001);
    }

    public function test_a_property_reports_who_owns_it_and_what_is_unallocated(): void
    {
        $ledger = $this->app->make(OwnershipLedger::class);

        $owner = Owner::query()->create([
            'organization_id' => $this->organization->getKey(),
            'first_name' => 'Partial',
        ]);

        $ledger->assign($this->property, $owner, ['ownership_percentage' => 65]);

        $response = $this->actingAsUser($this->admin, $this->organization)
            ->getJson("/api/v1/properties/{$this->property->getKey()}/ownership");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('allocated_percentage', 65)
            ->assertJsonPath('unallocated_percentage', 35);
    }

    public function test_portal_access_needs_an_email_address(): void
    {
        $owner = Owner::query()->create([
            'organization_id' => $this->organization->getKey(),
            'first_name' => 'Nameless',
        ]);

        $this->actingAsUser($this->admin, $this->organization)
            ->postJson("/api/v1/owners/{$owner->getKey()}/portal")
            ->assertStatus(422);
    }

    public function test_granting_portal_access_creates_a_scoped_membership(): void
    {
        $ledger = $this->app->make(OwnershipLedger::class);

        $owner = Owner::query()->create([
            'organization_id' => $this->organization->getKey(),
            'first_name' => 'Ana',
            'email' => 'ana-'.uniqid().'@example.test',
        ]);

        $ledger->assign($this->property, $owner, ['ownership_percentage' => 100]);

        $this->actingAsUser($this->admin, $this->organization)
            ->postJson("/api/v1/owners/{$owner->getKey()}/portal", ['send_invitation' => false])
            ->assertOk()
            ->assertJsonPath('data.portal_enabled', true);

        $owner->refresh();

        $this->assertNotNull($owner->user_id);

        $membership = $this->membershipOf($owner->user, $this->organization);

        $this->assertTrue((bool) $membership->restricted_to_properties);
        $this->assertSame('owner', $membership->default_portal);
    }

    public function test_withdrawing_portal_access_suspends_rather_than_deletes(): void
    {
        $owner = Owner::query()->create([
            'organization_id' => $this->organization->getKey(),
            'first_name' => 'Ana',
            'email' => 'ana-'.uniqid().'@example.test',
        ]);

        $this->actingAsUser($this->admin, $this->organization)
            ->postJson("/api/v1/owners/{$owner->getKey()}/portal", ['send_invitation' => false]);

        $userId = $owner->fresh()->user_id;

        $this->actingAsUser($this->admin, $this->organization)
            ->deleteJson("/api/v1/owners/{$owner->getKey()}/portal")
            ->assertOk()
            ->assertJsonPath('data.portal_enabled', false);

        // The account survives: it may be their only one, and the audit trail
        // of what they did must still resolve to a person.
        $this->assertNotNull(User::query()->find($userId));
        $this->assertSame('suspended', $this->membershipOf(
            User::query()->find($userId),
            $this->organization,
        )->status->value);
    }

    public function test_a_management_agreement_records_the_terms_that_get_disputed(): void
    {
        $owner = Owner::query()->create([
            'organization_id' => $this->organization->getKey(),
            'first_name' => 'Ana',
        ]);

        $response = $this->actingAsUser($this->admin, $this->organization)
            ->postJson("/api/v1/owners/{$owner->getKey()}/agreements", [
                'name' => 'Standard management',
                'commission_model' => 'percent_of_revenue',
                'commission_rate' => 20,
                'commission_on_accommodation' => true,
                'commission_on_fees' => false,
                'deduct_channel_commission_first' => true,
                'owner_pays_maintenance' => true,
                'starts_on' => now()->toDateString(),
            ]);

        $response->assertCreated()
            // "20% of what, exactly" is the question every statement argument
            // turns on, so each part is explicit rather than implied.
            ->assertJsonPath('data.commission_on_accommodation', true)
            ->assertJsonPath('data.commission_on_fees', false)
            ->assertJsonPath('data.deduct_channel_commission_first', true)
            ->assertJsonPath('data.is_in_force', true);

        $this->assertEqualsWithDelta(20.0, $response->json('data.commission_rate'), 0.0001);
    }

    public function test_deactivating_an_owner_does_not_delete_them(): void
    {
        $owner = Owner::query()->create([
            'organization_id' => $this->organization->getKey(),
            'first_name' => 'Ana',
        ]);

        $this->actingAsUser($this->admin, $this->organization)
            ->deleteJson("/api/v1/owners/{$owner->getKey()}")
            ->assertOk();

        // Statements, payouts and ledger entries all reference this record.
        $this->assertNotNull(Owner::query()->find($owner->getKey()));
        $this->assertSame('inactive', $owner->fresh()->status);
    }

    public function test_another_tenants_owner_is_invisible(): void
    {
        $owner = Owner::query()->create([
            'organization_id' => $this->organization->getKey(),
            'first_name' => 'Ours',
        ]);

        $otherOrganization = $this->createOrganization();
        $intruder = $this->createUser($otherOrganization, [RoleRegistry::ORGANIZATION_ADMIN]);

        $this->actingAsUser($intruder, $otherOrganization)
            ->getJson("/api/v1/owners/{$owner->getKey()}")
            ->assertNotFound();
    }
}
