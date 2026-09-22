<?php

declare(strict_types=1);

namespace Tests\Feature\Automation;

use App\Domain\Automation\Jobs\ExecuteAutomationRun;
use App\Domain\Automation\Models\AutomationRule;
use App\Domain\Automation\Models\AutomationRun;
use App\Domain\Automation\Services\ActionRunner;
use App\Domain\Automation\Services\AutomationEngine;
use App\Domain\Automation\Services\ScheduledAutomationPlanner;
use App\Domain\Listings\Models\Listing;
use App\Domain\Messaging\Models\Message;
use App\Domain\Messaging\Models\MessageTemplate;
use App\Domain\Operations\Models\Task;
use App\Domain\Organization\Models\Organization;
use App\Domain\Properties\Models\Property;
use App\Domain\Reservations\DataObjects\ReservationRequest;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Events\ReservationConfirmed;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Reservations\Services\ReservationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Automation.
 *
 * What these tests protect:
 *
 *  - A rule fires from the event stream without the code that raised the event
 *    knowing automation exists.
 *  - Every decision is recorded, including the decision to do nothing, with the
 *    result of each condition. "Why did this guest get that message?" and "why
 *    didn't they?" are the only two questions automation ever raises.
 *  - Running twice does not message a guest twice.
 *  - Conditions are re-checked after the delay, so a booking cancelled in the
 *    meantime does not still receive its arrival instructions.
 */
class AutomationEngineTest extends TestCase
{
    use RefreshDatabase;

    private AutomationEngine $engine;

    private ActionRunner $actions;

    private ReservationService $reservations;

    private Organization $organization;

    private Property $property;

    private Listing $listing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->engine = $this->app->make(AutomationEngine::class);
        $this->actions = $this->app->make(ActionRunner::class);
        $this->reservations = $this->app->make(ReservationService::class);

        $this->organization = $this->createOrganization(['base_currency' => 'EUR']);

        $this->property = Property::factory()->active()->create([
            'organization_id' => $this->organization->getKey(),
            'currency' => 'EUR',
            'base_rate' => 10000,
            'max_occupancy' => 6,
            'timezone' => 'Europe/Lisbon',
        ]);

        $this->listing = Listing::factory()->published()->create([
            'organization_id' => $this->organization->getKey(),
            'property_id' => $this->property->getKey(),
            'currency' => 'EUR',
            'minimum_nights' => 1,
        ]);
    }

    public function test_confirming_a_booking_runs_a_matching_rule_end_to_end(): void
    {
        $template = $this->template('Welcome {{ guest.first_name }} to {{ property.name }}.');

        $this->rule([
            'trigger_event' => 'reservation.confirmed',
            'actions' => [[
                'type' => 'send_message',
                'parameters' => ['template_id' => $template->getKey()],
            ]],
        ]);

        // Nothing here mentions automation. The reservation service does not
        // know it exists; the event stream is the whole seam.
        $reservation = $this->book();

        $run = AutomationRun::query()->first();

        $this->assertNotNull($run);
        $this->assertSame(AutomationRun::COMPLETED, $run->status);

        $message = Message::query()->where('direction', Message::OUTBOUND)->first();

        $this->assertNotNull($message);
        $this->assertStringContainsString('Marta', $message->body);
        $this->assertStringContainsString($this->property->name, $message->body);

        // Provenance: a manager can always tell this was not written by a
        // person.
        $this->assertSame('system', $message->author_type);
        $this->assertNotNull($message->automation_rule_id);
        $this->assertSame($template->getKey(), $message->template_id);
    }

    public function test_a_rule_whose_conditions_fail_records_why_rather_than_vanishing(): void
    {
        $this->rule([
            'trigger_event' => 'reservation.confirmed',
            'conditions' => [
                'match' => 'all',
                'rules' => [
                    ['field' => 'nights', 'operator' => 'greater_than_or_equal', 'value' => 7],
                ],
            ],
            'actions' => [['type' => 'add_internal_note', 'parameters' => ['body' => 'Long stay.']]],
        ]);

        $this->book(nights: 3);

        $run = AutomationRun::query()->first();

        $this->assertNotNull($run, 'A rule that declined to act must still leave a record.');
        $this->assertSame(AutomationRun::SKIPPED, $run->status);
        $this->assertNotEmpty($run->skip_reason);

        // Condition by condition, so the answer is readable without rerunning
        // anything.
        $trace = $run->condition_results;
        $this->assertCount(1, $trace);
        $this->assertSame('nights', $trace[0]['field']);
        $this->assertSame(3, $trace[0]['actual']);
        $this->assertFalse($trace[0]['result']);

        $this->assertSame(0, Message::query()->where('direction', Message::OUTBOUND)->count());
    }

    public function test_conditions_that_hold_let_the_rule_act(): void
    {
        $this->rule([
            'trigger_event' => 'reservation.confirmed',
            'conditions' => [
                'match' => 'all',
                'rules' => [
                    ['field' => 'nights', 'operator' => 'greater_than_or_equal', 'value' => 3],
                    ['field' => 'source', 'operator' => 'in', 'value' => ['direct', 'airbnb']],
                ],
            ],
            'actions' => [['type' => 'tag_reservation', 'parameters' => ['tags' => ['long-stay']]]],
        ]);

        $reservation = $this->book(nights: 5);

        $run = AutomationRun::query()->first();

        $this->assertSame(AutomationRun::COMPLETED, $run->status);
        $this->assertContains('long-stay', $reservation->fresh()->tagNames());
    }

    public function test_the_same_event_cannot_run_a_rule_twice(): void
    {
        $template = $this->template('Hello {{ guest.first_name }}.');

        $rule = $this->rule([
            'trigger_event' => 'reservation.confirmed',
            'actions' => [['type' => 'send_message', 'parameters' => ['template_id' => $template->getKey()]]],
        ]);

        $reservation = $this->book();

        $this->assertSame(1, AutomationRun::query()->count());

        // A redelivered webhook, a replayed event, two workers racing.
        $context = $this->engine->contextFor(
            new ReservationConfirmed($reservation),
            AutomationRun::query()->first()->domain_event_id,
        );

        $this->assertNull($this->engine->schedule($rule, $context));
        $this->assertNull($this->engine->schedule($rule, $context));

        $this->assertSame(1, AutomationRun::query()->count());
        $this->assertSame(1, Message::query()->where('direction', Message::OUTBOUND)->count());
    }

    public function test_a_rule_scoped_to_other_properties_creates_no_run_at_all(): void
    {
        $other = Property::factory()->active()->create([
            'organization_id' => $this->organization->getKey(),
            'currency' => 'EUR',
        ]);

        $this->rule([
            'trigger_event' => 'reservation.confirmed',
            'property_ids' => [$other->getKey()],
            'actions' => [['type' => 'add_internal_note', 'parameters' => ['body' => 'Note.']]],
        ]);

        $this->book();

        // Not a skipped run: a rule about other properties never had an
        // opinion about this booking, and a log full of those is a log nobody
        // reads.
        $this->assertSame(0, AutomationRun::query()->count());
    }

    public function test_conditions_are_re_checked_after_the_delay(): void
    {
        // The queue is faked so the delayed job is held rather than run: the
        // sync driver ignores delays entirely, which would collapse the very
        // gap this test is about.
        Queue::fake();

        $template = $this->template('See you soon, {{ guest.first_name }}.');

        $this->rule([
            'trigger_event' => 'reservation.confirmed',
            'conditions' => [
                'match' => 'all',
                'rules' => [['field' => 'status', 'operator' => 'equals', 'value' => 'confirmed']],
            ],
            'delay_minutes' => 60,
            'actions' => [['type' => 'send_message', 'parameters' => ['template_id' => $template->getKey()]]],
        ]);

        $reservation = $this->book();

        $run = AutomationRun::query()->first();
        $this->assertSame(AutomationRun::PENDING, $run->status);
        $this->assertNotNull($run->scheduled_for);

        // The work really was deferred rather than done inline.
        Queue::assertPushed(ExecuteAutomationRun::class);

        // The guest cancels during the delay.
        $this->reservations->cancel($reservation, 'Changed plans');

        $this->engine->execute($run->fresh(), $this->actions);

        // The condition was evaluated now, not an hour ago, so the cancelled
        // booking does not still get its message.
        $this->assertSame(AutomationRun::SKIPPED, $run->fresh()->status);
        $this->assertSame(0, Message::query()->where('direction', Message::OUTBOUND)->count());
    }

    public function test_a_rule_can_raise_operational_work(): void
    {
        $this->rule([
            'trigger_event' => 'reservation.confirmed',
            'actions' => [[
                'type' => 'create_task',
                'parameters' => [
                    'kind' => 'preparation',
                    'title' => 'Stock the welcome hamper',
                    'priority' => 'high',
                    'estimated_minutes' => 30,
                ],
            ]],
        ]);

        $reservation = $this->book();

        $task = Task::query()->where('generated_by', 'automation')->first();

        $this->assertNotNull($task);
        $this->assertSame('Stock the welcome hamper', $task->title);
        $this->assertSame($this->property->getKey(), $task->property_id);
        $this->assertSame($reservation->getKey(), $task->reservation_id);
    }

    public function test_one_failing_action_does_not_abandon_the_rest(): void
    {
        $this->rule([
            'trigger_event' => 'reservation.confirmed',
            'actions' => [
                // A template that no longer exists.
                ['type' => 'send_message', 'parameters' => ['template_id' => '01JQQQQQQQQQQQQQQQQQQQQQQQ']],
                ['type' => 'create_task', 'parameters' => ['kind' => 'cleaning', 'title' => 'Pre-arrival check']],
            ],
        ]);

        $this->book();

        $run = AutomationRun::query()->first();

        $this->assertSame(AutomationRun::FAILED, $run->status);

        // The clean was still raised: a bounced message must not cost the
        // property its preparation.
        $this->assertNotNull(Task::query()->where('title', 'Pre-arrival check')->first());

        $results = $run->action_results;
        $this->assertSame('failed', $results[0]['status']);
        $this->assertSame('completed', $results[1]['status']);
    }

    public function test_an_unknown_action_fails_closed_and_says_so(): void
    {
        $this->rule([
            'trigger_event' => 'reservation.confirmed',
            'actions' => [['type' => 'transfer_all_the_money', 'parameters' => ['to' => 'somewhere']]],
        ]);

        $this->book();

        $run = AutomationRun::query()->first();

        $this->assertSame(AutomationRun::FAILED, $run->status);
        $this->assertSame('failed', $run->action_results[0]['status']);
        $this->assertStringContainsString('Unknown action', $run->action_results[0]['reason']);
    }

    public function test_a_scheduled_rule_fires_in_the_properties_own_timezone(): void
    {
        $planner = $this->app->make(ScheduledAutomationPlanner::class);

        $rule = $this->rule([
            'trigger_type' => AutomationRule::TRIGGER_SCHEDULE,
            'trigger_event' => null,
            'trigger_anchor' => AutomationRule::ANCHOR_CHECK_IN,
            'trigger_offset_minutes' => -3 * 24 * 60,
            'trigger_time_of_day' => '10:00',
            'actions' => [['type' => 'add_internal_note', 'parameters' => ['body' => 'Arrival is in three days.']]],
        ]);

        $reservation = $this->book(startOffset: 30);

        $fireAt = $planner->firingMoment($rule, $reservation);

        $this->assertNotNull($fireAt);

        // Three days before arrival, at 10:00 *in Lisbon* — not at 10:00 on
        // the server's clock, which for a portfolio spanning timezones would be
        // the middle of the night for a third of the guests.
        $local = $fireAt->setTimezone('Europe/Lisbon');

        $this->assertSame('10:00', $local->format('H:i'));
        $this->assertSame(
            $reservation->check_in_date->subDays(3)->toDateString(),
            $local->toDateString(),
        );
    }

    public function test_the_planner_fires_a_scheduled_rule_once_for_a_booking(): void
    {
        $planner = $this->app->make(ScheduledAutomationPlanner::class);

        $rule = $this->rule([
            'trigger_type' => AutomationRule::TRIGGER_SCHEDULE,
            'trigger_event' => null,
            'trigger_anchor' => AutomationRule::ANCHOR_CHECK_IN,
            'trigger_offset_minutes' => 0,
            'actions' => [['type' => 'add_internal_note', 'parameters' => ['body' => 'Guest arrives today.']]],
        ]);

        $reservation = $this->book(startOffset: 12);

        $fireAt = $planner->firingMoment($rule, $reservation);

        $window = [$fireAt->subMinute(), $fireAt->addMinute()];

        $first = $planner->plan(...$window);
        $this->assertSame(1, $first['scheduled']);

        // The same minute processed twice — an overlapping cron, a retry —
        // must not produce a second note.
        $second = $planner->plan(...$window);
        $this->assertSame(0, $second['scheduled']);

        $this->assertSame(1, AutomationRun::query()->where('automation_rule_id', $rule->getKey())->count());
    }

    public function test_an_inactive_rule_does_nothing(): void
    {
        $this->rule([
            'trigger_event' => 'reservation.confirmed',
            'is_active' => false,
            'actions' => [['type' => 'add_internal_note', 'parameters' => ['body' => 'Note.']]],
        ]);

        $this->book();

        $this->assertSame(0, AutomationRun::query()->count());
    }

    // ------------------------------------------------------------------

    private function rule(array $attributes): AutomationRule
    {
        return AutomationRule::query()->create(array_merge([
            'organization_id' => $this->organization->getKey(),
            'name' => 'Test rule',
            'trigger_type' => AutomationRule::TRIGGER_EVENT,
            'is_active' => true,
        ], $attributes));
    }

    private function template(string $body): MessageTemplate
    {
        return MessageTemplate::query()->create([
            'organization_id' => $this->organization->getKey(),
            'name' => 'Test template',
            'subject' => 'About your stay',
            'body' => $body,
        ]);
    }

    private function book(int $nights = 3, int $startOffset = 20): Reservation
    {
        return $this->reservations->create(new ReservationRequest(
            listing: $this->listing,
            checkIn: $this->date($startOffset),
            checkOut: $this->date($startOffset + $nights),
            adults: 2,
            status: ReservationStatus::Confirmed,
            guestAttributes: [
                'first_name' => 'Marta',
                'last_name' => 'Silva',
                'email' => 'guest-'.uniqid().'@example.test',
            ],
            bookedAt: $this->date(0),
        ));
    }

    private function date(int $offsetDays): CarbonImmutable
    {
        return CarbonImmutable::now($this->property->timezone)->startOfDay()->addDays($offsetDays);
    }
}
