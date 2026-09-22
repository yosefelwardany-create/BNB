<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Domain\Operations\Enums\TaskKind;
use App\Domain\Operations\Enums\TaskPriority;
use App\Domain\Operations\Enums\TaskStatus;
use App\Domain\Operations\Exceptions\InvalidTaskTransitionException;
use App\Domain\Operations\Models\ChecklistTemplate;
use App\Domain\Operations\Models\Task;
use App\Domain\Operations\Models\TaskChecklistItem;
use App\Domain\Operations\Services\TaskService;
use App\Domain\Organization\Models\Organization;
use App\Domain\Properties\Models\Property;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The claims these tests protect:
 *
 *  - A checklist is enforced, not decorative. Work cannot be signed off with
 *    items outstanding or a required photograph missing.
 *  - A failed item becomes maintenance work automatically, linked back to the
 *    finding, so nothing quietly goes nowhere.
 *  - Generation is idempotent, so a re-run or a race cannot produce two jobs
 *    for one departure.
 */
class TaskLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private TaskService $tasks;

    private Organization $organization;

    private Property $property;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tasks = $this->app->make(TaskService::class);
        $this->organization = $this->createOrganization(['base_currency' => 'EUR']);

        $this->property = Property::factory()->active()->create([
            'organization_id' => $this->organization->getKey(),
            'currency' => 'EUR',
        ]);
    }

    public function test_a_task_gets_a_readable_reference_derived_from_its_kind(): void
    {
        $clean = $this->task(TaskKind::Cleaning);
        $repair = $this->task(TaskKind::Maintenance);

        $this->assertStringStartsWith('CLE-', $clean->reference);
        $this->assertStringStartsWith('MAI-', $repair->reference);
        $this->assertNotSame($clean->reference, $repair->reference);
    }

    public function test_a_task_created_with_an_assignee_starts_assigned(): void
    {
        $user = $this->createUser($this->organization);

        $task = $this->task(TaskKind::Cleaning, ['assigned_to_id' => $user->getKey()]);

        $this->assertSame(TaskStatus::Assigned, $task->status);

        // And one created without anybody on it is waiting for somebody.
        $this->assertSame(TaskStatus::Pending, $this->task(TaskKind::Cleaning)->status);
    }

    public function test_maintenance_gets_a_service_level_from_its_priority(): void
    {
        $urgent = $this->task(TaskKind::Maintenance, ['priority' => TaskPriority::Urgent]);
        $low = $this->task(TaskKind::Maintenance, ['priority' => TaskPriority::Low]);

        $this->assertNotNull($urgent->sla_due_at);
        $this->assertNotNull($low->sla_due_at);

        // Four hours against a week: the gap is the point of a priority.
        $this->assertTrue($urgent->sla_due_at->lessThan($low->sla_due_at));
    }

    public function test_a_checklist_template_is_copied_onto_the_task(): void
    {
        $template = ChecklistTemplate::query()->create([
            'organization_id' => $this->organization->getKey(),
            'name' => 'Standard turnover',
            'kind' => TaskKind::Cleaning->value,
            'items' => [
                ['label' => 'Strip the beds'],
                ['label' => 'Photograph the living room', 'requires_photo' => true],
            ],
        ]);

        $task = $this->tasks->create([
            'kind' => TaskKind::Cleaning,
            'title' => 'Turnover',
            'property_id' => $this->property->getKey(),
        ], $template);

        $this->assertSame(2, $task->checklistItems()->count());

        // Copied, not referenced: editing the template later must not change a
        // checklist somebody already worked from.
        $template->forceFill(['items' => []])->save();

        $this->assertSame(2, $task->fresh()->checklistItems()->count());
    }

    public function test_a_task_cannot_be_completed_with_outstanding_checklist_items(): void
    {
        $task = $this->taskWithChecklist();

        $blockers = $this->tasks->completionBlockers($task);

        $this->assertCount(2, $blockers);

        $this->tasks->transitionTo($task, TaskStatus::InProgress);

        $this->expectException(InvalidTaskTransitionException::class);

        $this->tasks->complete($task);
    }

    public function test_a_task_cannot_be_completed_while_a_required_photograph_is_missing(): void
    {
        $task = $this->taskWithChecklist();

        foreach ($task->checklistItems()->get() as $item) {
            $this->tasks->completeChecklistItem($item, TaskChecklistItem::PASSED);
        }

        $blockers = $this->tasks->completionBlockers($task->fresh());

        // Both items are marked done, but one of them needed a photograph.
        $this->assertCount(1, $blockers);
        $this->assertStringContainsString('requires a photo', $blockers[0]);
    }

    public function test_a_supervisor_can_override_an_incomplete_checklist(): void
    {
        $task = $this->taskWithChecklist();

        $this->tasks->transitionTo($task, TaskStatus::InProgress);

        $completed = $this->tasks->complete($task, [], force: true);

        $this->assertSame(TaskStatus::Completed, $completed->status);
    }

    public function test_a_failed_checklist_item_raises_linked_maintenance_work(): void
    {
        $task = $this->taskWithChecklist(requirePhoto: false);

        $items = $task->checklistItems()->get();

        $this->tasks->completeChecklistItem($items[0], TaskChecklistItem::PASSED);
        $this->tasks->completeChecklistItem(
            $items[1],
            TaskChecklistItem::FAILED,
            'The blind in the second bedroom is broken.',
            'major',
        );

        $this->tasks->transitionTo($task, TaskStatus::InProgress);
        $this->tasks->complete($task->fresh());

        $followUp = Task::query()
            ->where('generated_by', 'checklist_failure')
            ->first();

        $this->assertNotNull($followUp);
        $this->assertSame(TaskKind::Maintenance, $followUp->kind);

        // A major finding becomes high-priority work, not a note nobody reads.
        $this->assertSame(TaskPriority::High, $followUp->priority);
        $this->assertStringContainsString('broken', (string) $followUp->description);

        // And the finding points at its fix.
        $this->assertSame($followUp->getKey(), $items[1]->fresh()->follow_up_task_id);
    }

    public function test_completing_a_task_twice_does_not_raise_the_follow_up_twice(): void
    {
        $task = $this->taskWithChecklist(requirePhoto: false);
        $items = $task->checklistItems()->get();

        $this->tasks->completeChecklistItem($items[0], TaskChecklistItem::PASSED);
        $this->tasks->completeChecklistItem($items[1], TaskChecklistItem::FAILED, 'Broken.', 'minor');

        $this->tasks->transitionTo($task, TaskStatus::InProgress);
        $this->tasks->complete($task->fresh());
        $this->tasks->complete($task->fresh());

        $this->assertSame(1, Task::query()->where('generated_by', 'checklist_failure')->count());
    }

    public function test_an_illegal_transition_is_refused(): void
    {
        $task = $this->task(TaskKind::Cleaning);

        $this->tasks->transitionTo($task, TaskStatus::Cancelled);

        // A cancelled task keeps its history; correcting one means raising new
        // work, not reviving this.
        $this->expectException(InvalidTaskTransitionException::class);

        $this->tasks->transitionTo($task->fresh(), TaskStatus::InProgress);
    }

    public function test_reassigning_resets_acceptance(): void
    {
        $first = $this->createUser($this->organization);
        $second = $this->createUser($this->organization);

        $task = $this->task(TaskKind::Cleaning, ['assigned_to_id' => $first->getKey()]);

        $this->tasks->transitionTo($task, TaskStatus::Accepted);
        $this->assertNotNull($task->fresh()->accepted_at);

        $this->tasks->assign($task->fresh(), $second->getKey());

        $task->refresh();

        // The new assignee has not agreed to anything yet.
        $this->assertSame(TaskStatus::Assigned, $task->status);
        $this->assertNull($task->accepted_at);
    }

    public function test_generation_keys_prevent_duplicate_work(): void
    {
        $this->task(TaskKind::Cleaning, ['generation_key' => 'turnover:abc']);

        $this->expectException(QueryException::class);

        $this->task(TaskKind::Cleaning, ['generation_key' => 'turnover:abc']);
    }

    // ------------------------------------------------------------------

    private function task(TaskKind $kind, array $attributes = []): Task
    {
        return $this->tasks->create(array_merge([
            'kind' => $kind,
            'title' => ucfirst($kind->value).' job',
            'property_id' => $this->property->getKey(),
        ], $attributes));
    }

    private function taskWithChecklist(bool $requirePhoto = true): Task
    {
        return $this->tasks->create([
            'kind' => TaskKind::Cleaning,
            'title' => 'Turnover',
            'property_id' => $this->property->getKey(),
            'checklist_items' => [
                ['label' => 'Strip the beds'],
                ['label' => 'Check the bedrooms', 'requires_photo' => $requirePhoto],
            ],
        ]);
    }
}
