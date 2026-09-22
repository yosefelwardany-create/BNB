<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Automation\Services\ScheduledAutomationPlanner;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Fires time-based automation rules.
 *
 * The window looks slightly further back than the schedule's cadence, so a run
 * that starts late — a busy worker, a container restart — cannot leave a gap
 * that a guest's arrival instructions fall through. Overlap is safe: each run
 * carries an idempotency key with a unique index behind it, so a moment
 * processed twice still produces one message.
 *
 * That trade is deliberate. Sending a guest the same message twice is a
 * embarrassment; never sending it is a bad stay. The design makes the first
 * impossible, so the second can be guarded against generously.
 */
class DispatchScheduledAutomation extends Command
{
    protected $signature = 'automation:dispatch-scheduled
        {--lookback=20 : How many minutes back to consider, which should exceed the schedule interval}
        {--since= : An explicit start instant, for replaying a period by hand}
        {--until= : An explicit end instant}';

    protected $description = 'Schedule automation rules whose trigger time has arrived';

    public function handle(ScheduledAutomationPlanner $planner): int
    {
        $until = $this->option('until') !== null
            ? CarbonImmutable::parse((string) $this->option('until'))
            : CarbonImmutable::now();

        $from = $this->option('since') !== null
            ? CarbonImmutable::parse((string) $this->option('since'))
            : $until->subMinutes(max(1, (int) $this->option('lookback')));

        if ($from->greaterThanOrEqualTo($until)) {
            $this->components->error('The start of the window must be before its end.');

            return self::FAILURE;
        }

        $result = $planner->plan($from, $until);

        $this->components->info(sprintf(
            'Automation window %s to %s: %d reservation(s) examined, %d run(s) scheduled.',
            $from->toDateTimeString(),
            $until->toDateTimeString(),
            $result['examined'],
            $result['scheduled'],
        ));

        return self::SUCCESS;
    }
}
