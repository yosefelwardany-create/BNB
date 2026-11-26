<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Domain\Organization\Models\Organization;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Models\Role;
use App\Domain\Users\Models\User;
use App\Domain\Users\Services\AccessControl;
use App\Domain\Users\Services\InvitationService;
use App\Domain\Users\Support\RoleRegistry;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Logins the platform adds to a client account, each seeing what it is given.
 *
 * A client account had one read-only login. The platform now adds more, from
 * the client's page in Accounts, and decides for each what it can see and
 * change. A login added to a client belongs to that client alone.
 */
class ClientLoginsTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private Organization $client;

    private Membership $holder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operator = $this->createPlatformAdmin();
        ['organization' => $this->client, 'membership' => $this->holder] = $this->createClientOrganization(['name' => 'BASHA']);
        $this->app->make(TenantContext::class)->clear();
    }

    public function test_a_login_added_to_a_client_sees_that_client_and_nothing_else(): void
    {
        Notification::fake();
        $this->createClientOrganization(['name' => 'Another client']);
        $this->app->make(TenantContext::class)->clear();

        $sent = $this->actingAs($this->operator, 'sanctum')
            ->postJson($this->path('team/invitations'), [
                'email' => 'frontdesk@example.test',
                'first_name' => 'Rana',
                'role_ids' => [$this->role(RoleRegistry::RESERVATIONS_AGENT)->getKey()],
                'deny' => ['guests.view'],
            ])
            ->assertCreated()
            ->assertJsonPath('data.email', 'frontdesk@example.test');

        // No email is relied on: the link comes back to be passed on.
        $link = (string) $sent->json('meta.accept_url');
        $this->assertStringContainsString('/invitations/', $link);

        $invitations = $this->app->make(InvitationService::class);
        $invitation = $invitations->findPendingByToken(basename($link));
        $this->assertNotNull($invitation, 'The link is the invitation.');
        $membership = $invitations->accept($invitation, ['password' => 'a-strong-password-123']);

        $login = $membership->user;
        $this->assertSame(
            [$this->client->getKey()],
            Membership::query()->withoutGlobalScope('organization')->where('user_id', $login->getKey())->pluck('organization_id')->all(),
        );

        $access = $this->app->make(AccessControl::class);
        $access->flushMemo();
        $held = $access->permissionsFor($login, $this->client);
        $this->assertContains('reservations.view', $held);
        $this->assertNotContains('guests.view', $held);
        $this->assertSame('admin', $membership->fresh()->default_portal);
    }

    public function test_the_platform_sees_and_changes_what_a_clients_login_can_do(): void
    {
        $this->actingAs($this->operator, 'sanctum');

        $team = $this->getJson($this->path('team'))->assertOk();
        $this->assertSame([$this->holder->getKey()], array_column($team->json('data.members'), 'id'));
        $this->assertSame(['owner'], array_column($team->json('data.members.0.roles'), 'slug'));
        $this->assertNotEmpty($this->getJson($this->path('team/options'))->assertOk()->json('data.roles'));

        // Nobody in a client account manages its team: the platform does. So
        // changing its login is not refused for leaving nobody to do it.
        $this->patchJson($this->path('team/'.$this->holder->getKey()), [
            'role_ids' => [$this->role(RoleRegistry::READ_ONLY)->getKey()],
            'deny' => ['financials.view'],
        ])->assertOk()->assertJsonPath('data.roles.0.slug', 'read-only');

        // Out of the client portal and into the workspace its role belongs to.
        $this->assertSame('admin', $this->holder->fresh()->default_portal);

        $this->postJson($this->path('team/'.$this->holder->getKey().'/deactivate'))->assertOk()->assertJsonPath('data.status', 'suspended');
        $this->postJson($this->path('team/'.$this->holder->getKey().'/reactivate'))->assertOk()->assertJsonPath('data.status', 'active');
    }

    public function test_another_accounts_login_is_out_of_reach_from_this_client(): void
    {
        ['membership' => $elsewhere] = $this->createClientOrganization(['name' => 'Elsewhere']);
        $this->app->make(TenantContext::class)->clear();

        $this->actingAs($this->operator, 'sanctum')
            ->patchJson($this->path('team/'.$elsewhere->getKey()), ['deny' => ['guests.view']])
            ->assertNotFound();
    }

    public function test_only_the_platform_reaches_a_clients_logins_this_way(): void
    {
        $admin = $this->createUser($this->client, [RoleRegistry::ORGANIZATION_ADMIN]);
        $this->app->make(TenantContext::class)->clear();

        // Not found rather than forbidden: the console does not say it exists.
        $this->actingAs($admin, 'sanctum')->getJson($this->path('team'))->assertNotFound();
    }

    public function test_the_links_handed_out_open_the_app_where_it_is_served(): void
    {
        // Production: the app shares the API's host, under /app. A link built
        // on the host alone was a 404 — invitations and password resets alike.
        config(['app.frontend_url' => config('app.url')]);
        Notification::fake();

        $link = $this->actingAs($this->operator, 'sanctum')
            ->postJson($this->path('team/invitations'), [
                'email' => 'night@example.test',
                'role_ids' => [$this->role(RoleRegistry::STAFF)->getKey()],
            ])
            ->assertCreated()
            ->json('meta.accept_url');

        $app = rtrim((string) config('app.url'), '/').'/app/';
        $this->assertStringStartsWith($app.'invitations/', $link);

        $reset = (new ResetPassword('a-token'))->toMail($this->holder->user)->actionUrl;
        $this->assertStringStartsWith($app.'reset-password?token=a-token', $reset);
    }

    private function path(string $rest): string
    {
        return '/api/v1/platform/organizations/'.$this->client->getKey().'/'.$rest;
    }

    private function role(string $slug): Role
    {
        return Role::query()->availableTo($this->client)->where('slug', $slug)->firstOrFail();
    }
}
