<?php

declare(strict_types=1);

namespace App\Domain\Agents\Services;

use App\Domain\Agents\DataObjects\AgentBrief;
use App\Domain\Agents\Enums\AgentCapability;
use App\Domain\Agents\Exceptions\AgentNotConfiguredException;
use App\Domain\Agents\Jobs\PublishPropertyAction;
use App\Domain\Agents\Models\AgentAction;
use App\Domain\Agents\Models\AgentActivity;
use App\Domain\Availability\Models\CalendarBlock;
use App\Domain\Availability\Models\CalendarDay;
use App\Domain\Channels\Models\ChannelListing;
use App\Domain\Integrations\Providers\Messaging\ChannelThreadTransport;
use App\Domain\Integrations\Registries\ChannelAdapterRegistry;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Services\ConversationService;
use App\Domain\Operations\Services\TaskService;
use App\Domain\Properties\Models\Property;
use App\Domain\Properties\Services\PropertyService;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Users\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

/**
 * What an agent is allowed to do, and the machinery for doing it.
 *
 * The agent can answer questions about a property. This is the other half: being
 * asked to *change* something — reply to the guest, close a weekend, put the
 * rate up — and having that actually reach the channel.
 *
 * ## Why this is a proposal queue rather than a function call
 *
 * Because most of these wait. An agent asked to block a weekend produces a
 * proposal; a person reads it; only then does anything reach Hostex. Code that
 * executed directly and checked a flag afterwards would be autonomous by
 * default with a setting that looked otherwise — the waiting has to be the
 * structure, not a branch inside it.
 *
 * ## Three rules that are not settings
 *
 * **Cancelling a booking is never autonomous.** Not a risk to be traded off: the
 * guest arranged their travel around it, money has moved, and there is no
 * version of "the agent misunderstood" that makes it acceptable.
 * {@see AgentCapability::mayEverBeAutonomous()} is a constant, so nothing a
 * customer types into their own configuration turns it on.
 *
 * **A capability nobody granted is refused**, however convincingly it was asked
 * for. The allow-list is read from the property's brief, and an agent naming
 * something outside it gets a refusal rather than a best effort.
 *
 * **Approving executes what was proposed**, not what the agent would propose now.
 * The arguments are stored and replayed; re-deriving them at approval time would
 * mean the thing somebody read is not the thing that ran.
 */
class AgentActions
{
    public function __construct(
        private readonly ChannelAdapterRegistry $adapters,
        private readonly ConversationService $conversations,
    ) {}

    /**
     * Record what the agent wants to do, and run it if it is allowed to.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function propose(
        Property $property,
        AgentCapability $capability,
        array $arguments,
        string $summary,
        ?User $requestedBy = null,
    ): AgentAction {
        $brief = AgentBrief::fromSettings($property->settings);
        if ($capability->isLivePropertyWrite()) {
            Validator::make($arguments, HostexAgentPublisher::rules($capability))->validate();
            $arguments['_live_target'] = app(HostexAgentPublisher::class)->targetSignature($property);
            if (! $brief->enabled || $requestedBy === null) {
                throw new AgentNotConfiguredException('Live property changes require an enabled agent and a signed-in manager request.');
            }
        }

        foreach (['conversation_id' => Conversation::class, 'reservation_id' => Reservation::class] as $key => $model) {
            if (isset($arguments[$key]) && ! $model::query()->whereKey($arguments[$key])
                ->where('organization_id', $property->organization_id)->where('property_id', $property->id)->exists()) {
                throw new AgentNotConfiguredException('The selected record does not belong to this property.');
            }
        }

        if (! in_array($capability->value, $brief->mayDo, true)) {
            throw new AgentNotConfiguredException(sprintf(
                'This property\'s agent is not allowed to %s. Turn it on in the agent settings if that is what you want.',
                mb_strtolower($capability->label()),
            ));
        }

        $autonomous = $capability->mayEverBeAutonomous()
            && in_array($capability->value, $brief->mayDoAlone, true);

        $action = AgentAction::query()->create([
            'organization_id' => $property->organization_id,
            'property_id' => $property->getKey(),
            'reservation_id' => $this->ulidOrNull($arguments['reservation_id'] ?? null),
            'conversation_id' => $this->ulidOrNull($arguments['conversation_id'] ?? null),
            'requested_by_id' => $requestedBy?->getKey(),
            'capability' => $capability,
            'arguments' => $arguments,
            'summary' => Str::limit($summary, 480),
            'status' => $autonomous ? AgentAction::STATUS_APPROVED : AgentAction::STATUS_PROPOSED,
            'was_autonomous' => $autonomous,
            // A proposal nobody decided on goes stale: approving a week-old
            // "block this weekend" approves a situation that has moved on.
            'expires_at' => $autonomous ? null : CarbonImmutable::now()->addHours(
                (int) config('pms.agents.actions.window_hours', 48),
            ),
            'decided_at' => $autonomous ? CarbonImmutable::now() : null,
        ]);

        if ($autonomous) {
            // No line here: `execute` writes the outcome, and two rows for one
            // action would read as twice the activity there was.
            return $this->dispatchOrExecute($action);
        }

        $this->log($action, 'proposed, waiting for a person');

        return $action;
    }

    /**
     * A person says yes, and it runs.
     */
    public function approve(AgentAction $action, User $approver): AgentAction
    {
        if (! in_array($action->capability->value, AgentBrief::fromSettings($action->property->settings)->mayDo, true)) {
            throw new AgentNotConfiguredException('This capability is no longer enabled for this property.');
        }
        if (! $action->isOpen()) {
            throw new AgentNotConfiguredException(
                'That is no longer waiting for a decision. It may have been decided already, or run out of time.',
            );
        }

        $claimed = AgentAction::query()->whereKey($action->id)->where('status', AgentAction::STATUS_PROPOSED)
            ->where('expires_at', '>', CarbonImmutable::now())->update([
                'status' => AgentAction::STATUS_APPROVED,
                'approved_by_id' => $approver->getKey(),
                'decided_at' => CarbonImmutable::now(),
            ]);
        if ($claimed !== 1) {
            throw new AgentNotConfiguredException('That action has already been decided or expired.');
        }
        $action->refresh();

        return $this->dispatchOrExecute($action);
    }

    public function reject(AgentAction $action, User $approver, ?string $because = null): AgentAction
    {
        if (! $action->isOpen()) {
            throw new AgentNotConfiguredException('That is no longer waiting for a decision.');
        }

        $claimed = AgentAction::query()->whereKey($action->id)->where('status', AgentAction::STATUS_PROPOSED)
            ->where('expires_at', '>', CarbonImmutable::now())->update([
                'status' => AgentAction::STATUS_REJECTED,
                'approved_by_id' => $approver->getKey(),
                'outcome' => $because,
                'decided_at' => CarbonImmutable::now(),
            ]);
        if ($claimed !== 1) {
            throw new AgentNotConfiguredException('That action has already been decided or expired.');
        }
        $action->refresh();

        $this->log($action, 'rejected by a person');

        return $action;
    }

    /**
     * Do the thing.
     *
     * Private: nothing executes except through `propose` or `approve`, so there
     * is no path that skips the allow-list.
     */
    private function dispatchOrExecute(AgentAction $action): AgentAction
    {
        if (! $action->capability->isLivePropertyWrite()) {
            return $this->execute($action);
        }
        $action->update(['outcome' => 'Queued for this property only. No source change is confirmed yet.']);
        PublishPropertyAction::dispatch($action->id, $action->organization_id)->afterCommit();

        return $action;
    }

    private function execute(AgentAction $action): AgentAction
    {
        try {
            /*
             * Not wrapped in one transaction.
             *
             * A reply is delivered by the transport the moment
             * {@see ConversationService::send()} commits, and a transaction
             * around that would mean either rolling back a message that has
             * already left or holding a transaction open across an outbound HTTP
             * request. The handlers that write several rows open their own.
             */
            $reference = match ($action->capability) {
                AgentCapability::AddNote => $this->addNote($action),
                AgentCapability::SendMessage => $this->sendMessage($action),
                AgentCapability::BlockDates => $this->setAvailability($action, blocked: true),
                AgentCapability::UnblockDates => $this->setAvailability($action, blocked: false),
                AgentCapability::SetRate => $this->setRate($action),
                AgentCapability::CancelReservation => $this->cancelReservation($action),
                AgentCapability::UpdateProperty => $this->updateProperty($action),
                AgentCapability::CreateTask => $this->createTask($action),
            };
        } catch (Throwable $e) {
            $action->forceFill([
                'status' => AgentAction::STATUS_FAILED,
                // The channel's own words. A 401 here means a token and a 422
                // means the dates; "the action failed" means neither.
                'outcome' => Str::limit($e->getMessage(), 1000),
                'executed_at' => CarbonImmutable::now(),
            ])->save();

            $this->log($action, 'failed: '.Str::limit($e->getMessage(), 200));

            return $action;
        }

        $action->forceFill([
            'status' => AgentAction::STATUS_EXECUTED,
            'external_reference' => $reference,
            'executed_at' => CarbonImmutable::now(),
        ])->save();

        $this->log($action, 'done');

        return $action;
    }

    private function addNote(AgentAction $action): ?string
    {
        $conversation = $action->conversation;

        if ($conversation === null) {
            throw new AgentNotConfiguredException('There is no conversation to leave a note on.');
        }

        if ($conversation->property_id !== $action->property_id || $conversation->organization_id !== $action->organization_id) {
            throw new AgentNotConfiguredException('The conversation does not belong to this property.');
        }
        Validator::make($action->arguments, ['body' => 'required|string|max:10000'])->validate();

        return $this->conversations
            ->addNote($conversation, (string) ($action->arguments['body'] ?? ''))
            ->getKey();
    }

    public static function propertyRules(): array
    {
        return [
            'name' => 'sometimes|required|string|max:255',
            'summary' => 'sometimes|nullable|string|max:1000',
            'description' => 'sometimes|nullable|string|max:20000',
            'house_rules' => 'sometimes|nullable|string|max:10000',
            'internal_notes' => 'sometimes|nullable|string|max:10000',
            'bedrooms' => 'sometimes|required|integer|min:0|max:100',
            'beds' => 'sometimes|required|integer|min:0|max:200',
            'bathrooms' => 'sometimes|required|numeric|min:0|max:100',
            'max_occupancy' => 'sometimes|required|integer|min:1|max:200',
            'timezone' => 'sometimes|required|timezone',
            'check_in_time' => 'sometimes|required|date_format:H:i',
            'check_out_time' => 'sometimes|required|date_format:H:i',
        ];
    }

    public static function taskRules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'description' => 'sometimes|nullable|string|max:10000',
            'kind' => 'required|in:cleaning,maintenance,inspection,restocking,preparation,guest_request,custom',
            'priority' => 'sometimes|in:low,normal,high,urgent',
        ];
    }

    private function updateProperty(AgentAction $action): string
    {
        $attributes = Validator::make($action->arguments, self::propertyRules())->validate();
        if ($attributes === []) {
            throw new AgentNotConfiguredException('No supported property fields were provided.');
        }
        $property = $action->property;
        $settings = $property->settings ?? [];
        $settings['hostex_overrides'] = array_values(array_unique([...($settings['hostex_overrides'] ?? []), ...array_keys($attributes)]));
        app(PropertyService::class)->update($property, $attributes + ['settings' => $settings]);

        return 'Updated '.implode(', ', array_keys($attributes));
    }

    private function createTask(AgentAction $action): string
    {
        $attributes = Validator::make($action->arguments, self::taskRules())->validate();
        $task = app(TaskService::class)->create($attributes + ['property_id' => $action->property_id]);

        return $task->reference;
    }

    /**
     * Put the agent's reply into the guest's own thread on the channel.
     *
     * Through {@see ConversationService::send()}, which is the same call the
     * inbox makes when a person types a reply — not through the channel adapter
     * directly. That matters more than it looks: `send()` picks the `channel`
     * transport for a thread that came from one, and
     * {@see ChannelThreadTransport}
     * then does the addressing, the four preconditions, the retry classification
     * and the live-versus-simulated distinction. Calling the adapter here as
     * well would have sent the guest two copies of the same message, and then
     * recorded one of them.
     *
     * So the agent's reply is an ordinary outbound message that happens to be
     * marked as the agent's. There is one mechanism for getting words to a
     * guest, and the agent uses it.
     */
    private function sendMessage(AgentAction $action): ?string
    {
        $conversation = $action->conversation;
        $body = trim((string) ($action->arguments['body'] ?? ''));

        Validator::make($action->arguments, ['body' => 'required|string|max:10000'])->validate();
        if ($action->approved_by_id === null || $action->was_autonomous) {
            throw new AgentNotConfiguredException('A person must approve this guest message first.');
        }
        if ($conversation !== null && ($conversation->property_id !== $action->property_id
            || $conversation->organization_id !== $action->organization_id || $conversation->participant_type !== 'guest')) {
            throw new AgentNotConfiguredException('The guest conversation no longer belongs to this property.');
        }
        if ($conversation === null || $body === '') {
            throw new AgentNotConfiguredException('There is no thread to reply to, or nothing to say.');
        }

        $message = $this->conversations->send($conversation, [
            'body' => $body,
            // Marked as the agent's so the thread can show who wrote it. Never
            // hidden: a guest-visible reply nobody can attribute is the thing
            // this whole design exists to avoid.
            'is_ai_generated' => true,
            'ai_provider' => 'agent',
        ]);

        /*
         * The transport has already run by the time this returns, so its verdict
         * is on the row. A failure here becomes a failed action carrying the
         * transport's own words, and the message stays on the thread marked
         * failed rather than vanishing — somebody reading the conversation needs
         * to see that a reply was attempted.
         */
        if ($message->status === 'failed') {
            throw new AgentNotConfiguredException(
                (string) ($message->failure_reason ?? 'The channel would not take the message.'),
            );
        }

        /*
         * Delivered, or only recorded?
         *
         * A simulated adapter accepts the message and the record says nothing
         * reached the guest. The action says the same, because an operator
         * reading "done" on a proposal to answer a guest will believe the guest
         * was answered.
         */
        if ($message->status !== 'sent' && $message->status !== 'delivered') {
            $reason = $message->metadata['delivery']['reason'] ?? null;

            throw new AgentNotConfiguredException(sprintf(
                'Recorded on the thread, but not delivered to the guest. %s',
                is_string($reason) ? $reason : 'The channel is not connected for sending.',
            ));
        }

        return $message->external_message_id;
    }

    /**
     * Close nights, or re-open them.
     *
     * Blocking runs the same conflict check the calendar screen does, and for
     * the same reason: a block laid over a sold night hides a real booking from
     * the calendar, and the first anybody knows is two parties at one door. An
     * agent is exactly the caller most likely to try it, having been asked to
     * "close next weekend" by somebody who forgot about the booking.
     */
    private function setAvailability(AgentAction $action, bool $blocked): ?string
    {
        [$from, $to] = $this->dates($action);
        $property = $action->property;
        $end = $to->addDay();

        return DB::transaction(function () use ($action, $property, $from, $to, $end, $blocked): string {
            Property::query()->whereKey($property->id)->lockForUpdate()->firstOrFail();

            if (! $blocked) {
                return DB::transaction(function () use ($property, $from, $end): string {
                    $removed = CalendarBlock::query()
                        ->where('property_id', $property->getKey())
                        ->where('organization_id', $property->organization_id)
                        ->where('source', 'manual')->where('kind', '!=', CalendarBlock::KIND_EXTERNAL)
                        ->whereNull('channel_account_id')->whereNull('external_id')
                        ->overlapping($from->toDateString(), $end->toDateString())
                        ->lockForUpdate()
                        ->get();

                    foreach ($removed as $block) {
                        if ($block->start_date->lessThan($from) && $block->end_date->greaterThan($end)) {
                            $right = $block->replicate();
                            $right->start_date = $end;
                            $right->save();
                            $block->update(['end_date' => $from]);
                        } elseif ($block->start_date->lessThan($from)) {
                            $block->update(['end_date' => $from]);
                        } elseif ($block->end_date->greaterThan($end)) {
                            $block->update(['start_date' => $end]);
                        } else {
                            $block->delete();
                        }
                    }

                    return sprintf('re-opened %d block(s)', $removed->count());
                });
            }

            $conflicts = Reservation::query()
                ->where('property_id', $property->getKey())
                ->blocking()
                ->overlapping($from->toDateString(), $end->toDateString())
                ->get(['confirmation_code']);

            if ($conflicts->isNotEmpty()) {
                throw new AgentNotConfiguredException(sprintf(
                    'Those nights are already booked (%s). Blocking them would hide a real reservation.',
                    $conflicts->pluck('confirmation_code')->implode(', '),
                ));
            }

            $block = CalendarBlock::query()->create([
                'organization_id' => $property->organization_id,
                'property_id' => $property->getKey(),
                'kind' => CalendarBlock::KIND_MANUAL,
                'start_date' => $from->toDateString(),
                // The calendar stores an exclusive end, as a booking does: a block
                // "to the 5th" that also closed the 5th would quietly cost a night.
                'end_date' => $to->addDay()->toDateString(),
                'title' => Str::limit((string) ($action->arguments['reason'] ?? 'Blocked by the agent'), 150),
                'notes' => $action->summary,
                'created_by_id' => $action->requested_by_id,
            ]);

            return (string) $block->getKey();
        });
    }

    /**
     * Change what the nights in a range cost.
     *
     * Written as a per-day override on the listing's calendar, which is where
     * the pricing engine looks first — the same row the calendar screen writes,
     * so a rate set here and a rate set by hand are the same thing rather than
     * two mechanisms that disagree.
     */
    private function setRate(AgentAction $action): ?string
    {
        [$from, $to] = $this->dates($action);

        $amount = $action->arguments['amount_minor_units'] ?? null;

        if (filter_var($amount, FILTER_VALIDATE_INT) === false || (int) $amount <= 0 || (int) $amount > 100000000) {
            throw new AgentNotConfiguredException('A rate needs an amount, in minor units, above zero.');
        }

        /*
         * The primary listing, where there is one.
         *
         * A property can carry several — a direct listing and a channel-specific
         * one — and "the oldest" is not a decision, it is an accident of
         * whichever was created first. The primary is the one the rest of the
         * pricing screens treat as the property's own rate, so a rate set by the
         * agent and a rate set by hand land on the same row.
         */
        $listing = $action->property->listings()
            ->where('status', '!=', 'archived')
            ->orderByDesc('is_primary')
            ->orderBy('created_at')
            ->first();

        if ($listing === null) {
            throw new AgentNotConfiguredException(
                'This property has no live listing, so there is no calendar to price.',
            );
        }

        if (isset($action->arguments['currency']) && $action->arguments['currency'] !== $listing->currency) {
            throw new AgentNotConfiguredException('The requested currency does not match the listing currency. No conversion was applied.');
        }

        return DB::transaction(function () use ($listing, $action, $from, $to, $amount): string {
            $cursor = $from;
            $nights = 0;

            while ($cursor->lessThanOrEqualTo($to)) {
                CalendarDay::query()->updateOrCreate(
                    ['listing_id' => $listing->getKey(), 'calendar_date' => $cursor->toDateString()],
                    [
                        'organization_id' => $action->organization_id,
                        'rate_override' => (int) $amount,
                    ],
                );

                $cursor = $cursor->addDay();
                $nights++;
            }

            return sprintf('%d night(s) from %s', $nights, $from->toDateString());
        });
    }

    private function cancelReservation(AgentAction $action): ?string
    {
        $reservation = $action->reservation;

        if ($reservation === null) {
            throw new AgentNotConfiguredException('There is no booking to cancel.');
        }

        $mapping = $this->mappingFor($action);

        $result = $this->adapters->make($mapping->account->channel)->cancelReservation(
            $mapping,
            (string) $reservation->external_reservation_id,
            (string) ($action->arguments['reason'] ?? null),
        );

        if ($result->failed()) {
            throw new AgentNotConfiguredException((string) $result->errorMessage);
        }

        return $result->externalReference;
    }

    /**
     * The channel mapping this property's actions go through.
     */
    private function mappingFor(AgentAction $action): ChannelListing
    {
        $mapping = ChannelListing::query()
            ->with('account')
            ->where('property_id', $action->property_id)
            ->whereNotNull('listing_id')
            ->whereHas('account', fn ($query) => $query->where('status', 'connected'))
            ->first();

        if ($mapping?->account === null) {
            throw new AgentNotConfiguredException(
                'This property is not connected to a channel, so there is nowhere to send that.',
            );
        }

        return $mapping;
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function dates(AgentAction $action): array
    {
        $from = $action->arguments['from'] ?? null;
        $to = $action->arguments['to'] ?? null;
        Validator::make($action->arguments, [
            'from' => 'required|date_format:Y-m-d',
            'to' => 'required|date_format:Y-m-d|after_or_equal:from',
        ])->validate();

        if (! is_string($from) || ! is_string($to)) {
            throw new AgentNotConfiguredException('That needs a start and an end date.');
        }

        $start = CarbonImmutable::parse($from)->startOfDay();
        $end = CarbonImmutable::parse($to)->startOfDay();
        if ($start->diffInDays($end) > 399) {
            throw new AgentNotConfiguredException('Choose at most 400 nights per action.');
        }

        if ($end->lessThan($start)) {
            throw new AgentNotConfiguredException('The end date is before the start date.');
        }

        return [$start, $end];
    }

    private function log(AgentAction $action, string $what): void
    {
        AgentActivity::record([
            'organization_id' => $action->organization_id,
            'property_id' => $action->property_id,
            'reservation_id' => $action->reservation_id,
            'conversation_id' => $action->conversation_id,
            'actor_id' => $action->approved_by_id ?? $action->requested_by_id,
            'kind' => AgentActivity::KIND_ACTED,
            'summary' => sprintf('%s — %s: %s', $action->capability->label(), $what, $action->summary),
            'detail' => ['action_id' => $action->getKey(), 'capability' => $action->capability->value],
            'is_autonomous' => $action->was_autonomous,
        ]);
    }

    private function ulidOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
