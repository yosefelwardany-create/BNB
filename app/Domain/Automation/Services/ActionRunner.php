<?php

declare(strict_types=1);

namespace App\Domain\Automation\Services;

use App\Domain\Automation\DataObjects\AutomationContext;
use App\Domain\Automation\Models\AutomationRule;
use App\Domain\Messaging\Models\MessageTemplate;
use App\Domain\Messaging\Services\ConversationService;
use App\Domain\Notifications\Services\Notifier;
use App\Domain\Operations\Enums\TaskKind;
use App\Domain\Operations\Enums\TaskPriority;
use App\Domain\Operations\Models\ChecklistTemplate;
use App\Domain\Operations\Services\TaskService;
use Carbon\CarbonImmutable;

/**
 * Carries out what a rule decided to do.
 *
 * The action vocabulary is fixed and small. Every action is a named method on
 * this class taking a validated array of parameters — there is no expression
 * language, no callable stored in the database, nothing evaluated. A customer
 * writing an automation rule is choosing from a list, not writing a program,
 * which is the only way a rule that can message guests and create work is safe
 * to accept from a web form.
 *
 * Every action returns a record of what it did. That record is stored on the
 * run, so "why did this guest get that message?" has an answer that does not
 * require re-deriving anything.
 */
class ActionRunner
{
    public function __construct(
        private readonly ConversationService $conversations,
        private readonly TaskService $tasks,
        private readonly Notifier $notifier,
    ) {}

    /**
     * The complete action vocabulary, with the parameters each one takes.
     * Drives the rule editor as well as validation.
     *
     * @return array<string, array{label: string, description: string, parameters: array<string, string>}>
     */
    public static function vocabulary(): array
    {
        return [
            'send_message' => [
                'label' => 'Send a message',
                'description' => "Send a template to the guest on the reservation's thread.",
                'parameters' => [
                    'template_id' => 'The message template to send (required).',
                ],
            ],
            'add_internal_note' => [
                'label' => 'Add an internal note',
                'description' => 'Write a note on the thread, visible to staff only.',
                'parameters' => [
                    'body' => 'The note text (required).',
                ],
            ],
            'create_task' => [
                'label' => 'Create a task',
                'description' => 'Raise operational work against the property.',
                'parameters' => [
                    'kind' => 'cleaning, maintenance, inspection, restocking, preparation, guest_request or custom.',
                    'title' => 'What needs doing (required).',
                    'description' => 'Further detail.',
                    'priority' => 'low, normal, high or urgent.',
                    'offset_minutes' => 'Schedule this many minutes from now; negative for earlier.',
                    'estimated_minutes' => 'How long the work should take.',
                    'assign_to_team_id' => 'Give it to a team.',
                    'assign_to_user_id' => 'Give it to a person.',
                    'checklist_template_id' => 'Apply a checklist.',
                ],
            ],
            'notify_staff' => [
                'label' => 'Notify staff',
                'description' => 'Raise an in-app notification for everyone holding a permission.',
                'parameters' => [
                    'permission' => 'Who to tell, by permission (default tasks.view).',
                    'title' => 'The notification headline (required).',
                    'body' => 'Further detail.',
                    'priority' => 'low, normal, high or urgent.',
                ],
            ],
            'assign_conversation' => [
                'label' => 'Assign the conversation',
                'description' => 'Put the guest thread in somebody\'s name.',
                'parameters' => [
                    'user_id' => 'The person to assign it to.',
                    'team_id' => 'The team to assign it to.',
                ],
            ],
            'tag_reservation' => [
                'label' => 'Tag the reservation',
                'description' => 'Add a tag, so the booking can be found and filtered later.',
                'parameters' => [
                    'tags' => 'One or more tag names (required).',
                ],
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function availableActions(): array
    {
        return array_keys(self::vocabulary());
    }

    /**
     * Run one action.
     *
     * @param  array<string, mixed>  $action
     * @return array<string, mixed> what happened, for the run log
     */
    public function run(array $action, AutomationContext $context, AutomationRule $rule): array
    {
        $type = (string) ($action['type'] ?? '');
        $parameters = (array) ($action['parameters'] ?? []);

        if (! in_array($type, self::availableActions(), true)) {
            // Fails closed and loudly. An unrecognised action is a rule that
            // was written against a version of the product this one is not.
            return [
                'type' => $type,
                'status' => 'failed',
                'reason' => sprintf('Unknown action [%s]; nothing was done.', $type),
            ];
        }

        try {
            $outcome = match ($type) {
                'send_message' => $this->sendMessage($parameters, $context, $rule),
                'add_internal_note' => $this->addInternalNote($parameters, $context),
                'create_task' => $this->createTask($parameters, $context, $rule),
                'notify_staff' => $this->notifyStaff($parameters, $context),
                'assign_conversation' => $this->assignConversation($parameters, $context),
                'tag_reservation' => $this->tagReservation($parameters, $context),
            };
        } catch (\Throwable $exception) {
            return [
                'type' => $type,
                'status' => 'failed',
                'reason' => $exception->getMessage(),
            ];
        }

        return ['type' => $type] + $outcome;
    }

    // ------------------------------------------------------------------
    // The actions themselves
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    private function sendMessage(array $parameters, AutomationContext $context, AutomationRule $rule): array
    {
        if ($context->reservation === null) {
            return ['status' => 'skipped', 'reason' => 'The event has no reservation to message about.'];
        }

        $template = MessageTemplate::query()->find($parameters['template_id'] ?? null);

        if ($template === null) {
            return ['status' => 'failed', 'reason' => 'The message template no longer exists.'];
        }

        if (! $template->is_active) {
            return ['status' => 'skipped', 'reason' => 'The message template is inactive.'];
        }

        $conversation = $this->conversations->forReservation($context->reservation);

        $message = $this->conversations->sendTemplate(
            $conversation,
            $template,
            automationRuleId: $rule->getKey(),
        );

        return [
            'status' => 'completed',
            'message_id' => $message->getKey(),
            'conversation_id' => $conversation->getKey(),
            'template' => $template->name,
            // Whether a guest actually received it, rather than whether we
            // tried: the run log must not overstate what happened.
            'delivery' => $message->metadata['delivery'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    private function addInternalNote(array $parameters, AutomationContext $context): array
    {
        if ($context->reservation === null) {
            return ['status' => 'skipped', 'reason' => 'The event has no reservation thread to note against.'];
        }

        $body = trim((string) ($parameters['body'] ?? ''));

        if ($body === '') {
            return ['status' => 'failed', 'reason' => 'The note has no text.'];
        }

        $conversation = $this->conversations->forReservation($context->reservation);
        $message = $this->conversations->addNote($conversation, $body);

        return ['status' => 'completed', 'message_id' => $message->getKey()];
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    private function createTask(array $parameters, AutomationContext $context, AutomationRule $rule): array
    {
        $propertyId = $context->propertyId();

        if ($propertyId === null) {
            return ['status' => 'skipped', 'reason' => 'The event concerns no property.'];
        }

        $offset = (int) ($parameters['offset_minutes'] ?? 0);
        $start = CarbonImmutable::now()->addMinutes($offset);

        $checklist = isset($parameters['checklist_template_id'])
            ? ChecklistTemplate::query()->find($parameters['checklist_template_id'])
            : null;

        $task = $this->tasks->create([
            'kind' => TaskKind::tryFrom((string) ($parameters['kind'] ?? 'custom')) ?? TaskKind::Custom,
            'title' => (string) ($parameters['title'] ?? 'Automated task'),
            'description' => $parameters['description'] ?? sprintf('Raised by the automation rule "%s".', $rule->name),
            'property_id' => $propertyId,
            'unit_id' => $context->reservation?->unit_id,
            'reservation_id' => $context->reservation?->getKey(),
            'priority' => TaskPriority::tryFrom((string) ($parameters['priority'] ?? '')) ?? TaskPriority::Normal,
            'scheduled_start' => $start,
            'estimated_minutes' => $parameters['estimated_minutes'] ?? null,
            'team_id' => $parameters['assign_to_team_id'] ?? null,
            'assigned_to_id' => $parameters['assign_to_user_id'] ?? null,
            'generated_by' => 'automation',
            // One task per rule per subject: a redelivered event, or a rule
            // that fires twice for the same booking, cannot produce two.
            'generation_key' => sprintf('automation:%s:%s', $rule->getKey(), $context->subjectKey()),
        ], $checklist);

        return ['status' => 'completed', 'task_id' => $task->getKey(), 'reference' => $task->reference];
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    private function notifyStaff(array $parameters, AutomationContext $context): array
    {
        $title = trim((string) ($parameters['title'] ?? ''));

        if ($title === '') {
            return ['status' => 'failed', 'reason' => 'The notification has no title.'];
        }

        $notifications = $this->notifier->notifyPermissionHolders(
            organizationId: $context->organizationId,
            permission: (string) ($parameters['permission'] ?? 'tasks.view'),
            type: 'automation.'.$context->eventName,
            title: $title,
            body: $parameters['body'] ?? null,
            subject: $context->subject,
            priority: (string) ($parameters['priority'] ?? 'normal'),
        );

        return ['status' => 'completed', 'notified' => count($notifications)];
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    private function assignConversation(array $parameters, AutomationContext $context): array
    {
        if ($context->reservation === null) {
            return ['status' => 'skipped', 'reason' => 'The event has no reservation thread to assign.'];
        }

        $conversation = $this->conversations->forReservation($context->reservation);

        $this->conversations->assign(
            $conversation,
            $parameters['user_id'] ?? null,
            $parameters['team_id'] ?? null,
        );

        return ['status' => 'completed', 'conversation_id' => $conversation->getKey()];
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    private function tagReservation(array $parameters, AutomationContext $context): array
    {
        if ($context->reservation === null) {
            return ['status' => 'skipped', 'reason' => 'The event has no reservation to tag.'];
        }

        $tags = array_values(array_filter(array_map(
            static fn (mixed $tag): string => trim((string) $tag),
            (array) ($parameters['tags'] ?? []),
        )));

        if ($tags === []) {
            return ['status' => 'failed', 'reason' => 'No tags were given.'];
        }

        // Added, not synced: an automation must not remove tags a person put
        // there deliberately.
        $context->reservation->tagWith($tags);

        return ['status' => 'completed', 'tags' => $tags];
    }
}
