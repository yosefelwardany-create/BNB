<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Domain\Operations\Enums\TaskKind;
use App\Domain\Operations\Models\Task;
use App\Domain\Operations\Models\Team;
use App\Domain\Operations\Services\TaskService;
use App\Domain\Organization\Models\Organization;
use App\Domain\Properties\Models\Property;
use App\Domain\Users\Models\User;
use App\Domain\Users\Support\RoleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The operations API, over HTTP, as a client actually reaches it.
 *
 * The behaviour worth proving here is not that the routes exist but that the
 * authorization holds at the edge. A housekeeper holds `tasks.update` and
 * `tasks.complete` — they cannot do their job without them — and the whole
 * design rests on those permissions being bounded to their own rota by the
 * policy rather than being the keys to the portfolio.
 */
class TaskApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Property $property;

    private User $manager;

    private User $cleaner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->createOrganization(['base_currency' => 'EUR']);

        $this->property = Property::factory()->active()->create([
            'organization_id' => $this->organization->getKey(),
            'currency' => 'EUR',
        ]);

        $this->manager = $this->createUser($this->organization, [RoleRegistry::OPERATIONS_MANAGER]);
        $this->cleaner = $this->createUser($this->organization, [RoleRegistry::CLEANER]);
    }

    public function test_a_manager_can_create_a_task_with_a_checklist(): void
    {
        $response = $this->actingAsUser($this->manager, $this->organization)
            ->postJson('/api/v1/tasks', [
                'kind' => 'cleaning',
                'title' => 'Deep clean before the season',
                'property_id' => $this->property->getKey(),
                'priority' => 'high',
                'checklist_items' => [
                    ['label' => 'Descale the kettle'],
                    ['label' => 'Photograph the terrace', 'requires_photo' => true],
                ],
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.title', 'Deep clean before the season')
            ->assertJsonPath('data.priority', 'high')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonCount(2, 'data.checklist_items');

        // The enum decides what the interface may offer, not the client.
        $this->assertContains('assigned', $response->json('data.allowed_transitions'));
    }

    public function test_a_cleaner_sees_only_their_own_work(): void
    {
        $tasks = $this->app->make(TaskService::class);

        $theirs = $tasks->create([
            'kind' => TaskKind::Cleaning,
            'title' => 'Their turnover',
            'property_id' => $this->property->getKey(),
            'assigned_to_id' => $this->cleaner->getKey(),
        ]);

        $tasks->create([
            'kind' => TaskKind::Maintenance,
            'title' => 'Somebody else’s boiler',
            'property_id' => $this->property->getKey(),
        ]);

        $response = $this->actingAsUser($this->cleaner, $this->organization)
            ->getJson('/api/v1/tasks');

        $response->assertOk()->assertJsonCount(1, 'data');

        $this->assertSame($theirs->getKey(), $response->json('data.0.id'));

        // And the manager sees both.
        $this->actingAsUser($this->manager, $this->organization)
            ->getJson('/api/v1/tasks')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_a_cleaner_sees_work_given_to_their_crew(): void
    {
        $team = Team::query()->create([
            'organization_id' => $this->organization->getKey(),
            'name' => 'Housekeeping',
        ]);

        $team->members()->attach($this->membershipOf($this->cleaner, $this->organization)->getKey());

        $this->app->make(TaskService::class)->create([
            'kind' => TaskKind::Cleaning,
            'title' => 'Crew turnover',
            'property_id' => $this->property->getKey(),
            'team_id' => $team->getKey(),
        ]);

        // Work given to a crew must be visible to whoever might pick it up,
        // or the crew cannot function.
        $this->actingAsUser($this->cleaner, $this->organization)
            ->getJson('/api/v1/tasks')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_a_cleaner_cannot_touch_somebody_elses_task(): void
    {
        $other = $this->app->make(TaskService::class)->create([
            'kind' => TaskKind::Maintenance,
            'title' => 'Not theirs',
            'property_id' => $this->property->getKey(),
        ]);

        // They hold tasks.update — they need it for their own work — so the
        // route-level gate lets them in. The policy is what stops them here.
        $this->actingAsUser($this->cleaner, $this->organization)
            ->patchJson("/api/v1/tasks/{$other->getKey()}", ['title' => 'Mine now'])
            ->assertForbidden();

        $this->actingAsUser($this->cleaner, $this->organization)
            ->getJson("/api/v1/tasks/{$other->getKey()}")
            ->assertForbidden();

        $this->assertSame('Not theirs', $other->fresh()->title);
    }

    public function test_completing_a_task_reports_what_is_outstanding(): void
    {
        $task = $this->app->make(TaskService::class)->create([
            'kind' => TaskKind::Cleaning,
            'title' => 'Turnover',
            'property_id' => $this->property->getKey(),
            'assigned_to_id' => $this->cleaner->getKey(),
            'checklist_items' => [
                ['label' => 'Strip the beds'],
                ['label' => 'Restock the bathroom'],
            ],
        ]);

        $this->actingAsUser($this->cleaner, $this->organization)
            ->postJson("/api/v1/tasks/{$task->getKey()}/start");

        $response = $this->actingAsUser($this->cleaner, $this->organization)
            ->postJson("/api/v1/tasks/{$task->getKey()}/complete");

        // Not "something went wrong": the app can tell the cleaner exactly
        // what is left.
        $response->assertStatus(422)->assertJsonCount(2, 'blockers');

        $this->assertStringContainsString('Strip the beds', implode(' ', $response->json('blockers')));
    }

    public function test_recording_a_checklist_item_reports_the_remaining_blockers(): void
    {
        $task = $this->app->make(TaskService::class)->create([
            'kind' => TaskKind::Inspection,
            'title' => 'Quarterly inspection',
            'property_id' => $this->property->getKey(),
            'assigned_to_id' => $this->cleaner->getKey(),
            'checklist_items' => [
                ['label' => 'Test the smoke alarm'],
                ['label' => 'Check the boiler pressure'],
            ],
        ]);

        $item = $task->checklistItems()->first();

        $response = $this->actingAsUser($this->cleaner, $this->organization)
            ->patchJson("/api/v1/tasks/{$task->getKey()}/checklist/{$item->getKey()}", [
                'status' => 'passed',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'passed')
            ->assertJsonCount(1, 'remaining_blockers');
    }

    public function test_a_failed_item_requires_a_severity_so_follow_up_work_has_a_priority(): void
    {
        $task = $this->app->make(TaskService::class)->create([
            'kind' => TaskKind::Inspection,
            'title' => 'Inspection',
            'property_id' => $this->property->getKey(),
            'assigned_to_id' => $this->cleaner->getKey(),
            'checklist_items' => [['label' => 'Check the blinds']],
        ]);

        $item = $task->checklistItems()->first();

        $this->actingAsUser($this->cleaner, $this->organization)
            ->patchJson("/api/v1/tasks/{$task->getKey()}/checklist/{$item->getKey()}", [
                'status' => 'failed',
                'notes' => 'The cord is snapped.',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('severity');
    }

    public function test_the_board_groups_a_days_work(): void
    {
        $tasks = $this->app->make(TaskService::class);

        $tasks->create([
            'kind' => TaskKind::Cleaning,
            'title' => 'Unassigned turnover',
            'property_id' => $this->property->getKey(),
            'scheduled_start' => now()->addHours(2),
        ]);

        $tasks->create([
            'kind' => TaskKind::Maintenance,
            'title' => 'Overdue repair',
            'property_id' => $this->property->getKey(),
            'assigned_to_id' => $this->manager->getKey(),
            'scheduled_start' => now()->subDays(2),
            'due_at' => now()->subDay(),
        ]);

        $response = $this->actingAsUser($this->manager, $this->organization)
            ->getJson('/api/v1/tasks/board');

        $response->assertOk()
            ->assertJsonCount(1, 'data.unassigned')
            // Work that should already have been done stays on the board:
            // dropping it at midnight is how an overdue clean gets forgotten.
            ->assertJsonCount(1, 'data.overdue')
            ->assertJsonPath('summary.overdue', 1)
            ->assertJsonPath('summary.unassigned', 1);
    }

    public function test_a_task_is_cancelled_rather_than_deleted(): void
    {
        $task = $this->app->make(TaskService::class)->create([
            'kind' => TaskKind::Cleaning,
            'title' => 'Turnover',
            'property_id' => $this->property->getKey(),
        ]);

        $this->actingAsUser($this->manager, $this->organization)
            ->postJson("/api/v1/tasks/{$task->getKey()}/cancel", ['reason' => 'Booking fell through'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        // The record that a clean was scheduled and called off is part of what
        // happened at that property.
        $this->assertNotNull(Task::query()->find($task->getKey()));
        $this->assertSame('Booking fell through', $task->fresh()->cancellation_reason);
    }

    public function test_another_tenants_task_is_invisible(): void
    {
        $task = $this->app->make(TaskService::class)->create([
            'kind' => TaskKind::Cleaning,
            'title' => 'Ours',
            'property_id' => $this->property->getKey(),
        ]);

        $otherOrganization = $this->createOrganization();
        $intruder = $this->createUser($otherOrganization, [RoleRegistry::ORGANIZATION_ADMIN]);

        // Tenancy is enforced in the data layer, so the record simply does not
        // exist as far as this caller is concerned.
        $this->actingAsUser($intruder, $otherOrganization)
            ->getJson("/api/v1/tasks/{$task->getKey()}")
            ->assertNotFound();
    }
}
