<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Domain\Platform\Models\Tag;
use App\Support\Concerns\CrossTenantWriteException;
use App\Support\Tenancy\TenantNotResolvedException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tenant isolation is the single most important property of a multi-tenant
 * platform: a leak here exposes one customer's guests, revenue and messages to
 * another. These tests assert it at the data layer, where it is enforced,
 * rather than at the controller layer, where it could be forgotten.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_queries_only_return_records_from_the_bound_organization(): void
    {
        $first = $this->createOrganization(['name' => 'First Company']);
        Tag::query()->create(['name' => 'VIP', 'slug' => 'vip']);

        $second = $this->createOrganization(['name' => 'Second Company']);
        Tag::query()->create(['name' => 'Repeat guest', 'slug' => 'repeat-guest']);

        // Bound to the second organization, only its tag is visible.
        $this->assertSame(1, Tag::query()->count());
        $this->assertSame('Repeat guest', Tag::query()->first()->name);

        $this->actingForOrganization($first);

        $this->assertSame(1, Tag::query()->count());
        $this->assertSame('VIP', Tag::query()->first()->name);
    }

    public function test_finding_another_tenants_record_by_id_returns_nothing(): void
    {
        $first = $this->createOrganization();
        $foreignTag = Tag::query()->create(['name' => 'Confidential', 'slug' => 'confidential']);

        $this->createOrganization();

        // Even knowing the exact identifier, the record is not reachable.
        $this->assertNull(Tag::query()->find($foreignTag->getKey()));
    }

    public function test_new_records_inherit_the_bound_organization(): void
    {
        $organization = $this->createOrganization();

        $tag = Tag::query()->create(['name' => 'Long stay', 'slug' => 'long-stay']);

        $this->assertSame($organization->getKey(), $tag->organization_id);
    }

    public function test_writing_a_record_belonging_to_another_tenant_is_rejected(): void
    {
        $first = $this->createOrganization();
        $tag = Tag::query()->create(['name' => 'Mine', 'slug' => 'mine']);

        $this->createOrganization();

        // Re-hydrate the foreign record outside the scope, then try to save it
        // while acting as the second organization.
        $foreign = $this->withoutTenantScope(fn () => Tag::query()->find($tag->getKey()));

        $this->assertNotNull($foreign);

        $foreign->name = 'Renamed by another tenant';

        $this->expectException(CrossTenantWriteException::class);

        $foreign->save();
    }

    public function test_creating_a_tenant_scoped_record_without_a_tenant_fails_loudly(): void
    {
        // No organization bound: creating tenant data would produce an orphan
        // row visible to nobody, so it is an error rather than a silent write.
        $this->app->make(\App\Support\Tenancy\TenantContext::class)->clear();

        $this->expectException(TenantNotResolvedException::class);

        Tag::query()->create(['name' => 'Orphan', 'slug' => 'orphan']);
    }

    public function test_platform_scope_can_be_suspended_deliberately(): void
    {
        $this->createOrganization();
        Tag::query()->create(['name' => 'A', 'slug' => 'a']);

        $this->createOrganization();
        Tag::query()->create(['name' => 'B', 'slug' => 'b']);

        $all = $this->withoutTenantScope(fn () => Tag::query()->count());

        $this->assertSame(2, $all);
    }

    public function test_for_organization_scope_targets_a_specific_tenant(): void
    {
        $first = $this->createOrganization();
        Tag::query()->create(['name' => 'First tag', 'slug' => 'first-tag']);

        $second = $this->createOrganization();
        Tag::query()->create(['name' => 'Second tag', 'slug' => 'second-tag']);

        $fromFirst = Tag::query()->forOrganization($first)->get();

        $this->assertCount(1, $fromFirst);
        $this->assertSame('First tag', $fromFirst->first()->name);
    }
}
