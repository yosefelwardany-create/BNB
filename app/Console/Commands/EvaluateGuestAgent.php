<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Agents\Services\AgentEvaluator;
use App\Domain\Agents\Services\EvalScenarioSet;
use App\Domain\Organization\Models\Organization;
use App\Domain\Properties\Models\Property;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Score the guest agent against the scenarios in database/agent-evals.
 *
 * The point of this command is to make a prompt change measurable. Run it,
 * change a persona or a model, run it again, and the difference is a number
 * rather than an impression.
 *
 * It sends nothing. Every scenario produces a draft and the draft is scored —
 * which is what makes it safe to run against a real organization's live data,
 * and it is worth running there, because a real portfolio's facts are messier
 * than a fixture's.
 *
 * Exits non-zero when anything fails, so it can gate a deploy.
 */
class EvaluateGuestAgent extends Command
{
    protected $signature = 'agent:evaluate
        {--organization= : The organization to evaluate in}
        {--property= : A specific property id; defaults to the first active one}
        {--set=guest-questions : Which scenario file under database/agent-evals}
        {--strict : Fail on quality problems too, not only unsafe ones}
        {--json : Emit machine-readable output}';

    protected $description = 'Score the guest agent against known-answer scenarios without sending anything';

    public function handle(AgentEvaluator $evaluator, EvalScenarioSet $scenarios, TenantContext $tenancy): int
    {
        $organization = $this->organization($tenancy);

        if ($organization === null) {
            $this->components->error('No organization found. Pass --organization, or seed one.');

            return self::FAILURE;
        }

        try {
            $set = $scenarios->load((string) $this->option('set'));
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());
            $this->line('  Available: '.implode(', ', EvalScenarioSet::available()));

            return self::FAILURE;
        }

        return $tenancy->runAs($organization, function () use ($evaluator, $scenarios, $set): int {
            $property = $this->property();

            if ($property === null) {
                $this->components->error('No active property to evaluate against.');

                return self::FAILURE;
            }

            $outcome = $evaluator->runAll($set, $scenarios->resolver($property));

            if ($this->option('json')) {
                $this->line((string) json_encode($outcome, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                return $this->exitCode($outcome);
            }

            $this->report($property, $outcome);

            return $this->exitCode($outcome);
        });
    }

    /**
     * Only a safety failure fails the command.
     *
     * A quality failure is a thing to work on, and gating a deploy on it would
     * mean the suite gets disabled the first time somebody needs to ship. A
     * leaked door code is different in kind, and `--strict` is there for whoever
     * wants both.
     *
     * @param  array{total: int, passed: int, failed: int, unsafe: int, results: list<array<string, mixed>>}  $outcome
     */
    private function exitCode(array $outcome): int
    {
        if ($outcome['unsafe'] > 0) {
            return self::FAILURE;
        }

        return $this->option('strict') && $outcome['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array{total: int, passed: int, failed: int, unsafe: int, results: list<array<string, mixed>>}  $outcome
     */
    private function report(Property $property, array $outcome): void
    {
        $this->components->info(sprintf('Evaluating the agent for %s', $property->display_name ?? $property->name));
        $this->newLine();

        foreach ($outcome['results'] as $result) {
            $answer = $result['answer'];

            $mark = match (true) {
                ! $result['safe'] => '<fg=red;options=bold>UNSAFE</>',
                $result['passed'] => '<fg=green>pass</>  ',
                default => '<fg=yellow>weak</>  ',
            };

            $this->line(sprintf(' %s %s', $mark, $result['name']));

            $this->line(sprintf(
                '      <fg=gray>%s · %d%% · %s</>',
                $answer['intent'],
                (int) round(((float) $answer['confidence']) * 100),
                $answer['would_auto_send'] ? 'would send on its own' : 'held: '.($answer['held_because'] ?? '—'),
            ));

            foreach ($result['safety_failures'] as $failure) {
                $this->line(sprintf('        <fg=red>→ %s</>', $failure));
            }

            foreach ($result['quality_failures'] as $failure) {
                $this->line(sprintf('        <fg=yellow>→ %s</>', $failure));
            }
        }

        $this->newLine();

        $first = $outcome['results'][0]['answer'] ?? null;

        if ($first !== null && ($first['is_simulated'] ?? true)) {
            // Without this, a green run against the echo provider reads as
            // evidence the agent works.
            $this->components->warn(
                'These answers were produced by a simulation, not a model: '
                .(string) ($first['simulation_reason'] ?? 'no live AI provider is configured.')
                ."\n"
                .'The gates are genuinely under test; the quality of the wording is not.',
            );
        }

        if ($outcome['unsafe'] > 0) {
            $this->components->error(sprintf(
                '%d scenario(s) were UNSAFE. Nothing else matters until that is zero.',
                $outcome['unsafe'],
            ));

            return;
        }

        $this->components->info(sprintf(
            'Safety: %d/%d clean. Quality: %d/%d fully correct.',
            $outcome['total'],
            $outcome['total'],
            $outcome['passed'],
            $outcome['total'],
        ));

        if ($outcome['failed'] > 0) {
            $this->line(
                '  <fg=gray>The weak ones are what a better model and a better brief fix. '
                .'They are not a reason to hold a release.</>',
            );
        }
    }

    private function organization(TenantContext $tenancy): ?Organization
    {
        return $tenancy->withoutScope(function (): ?Organization {
            $id = $this->option('organization');

            return $id === null
                ? Organization::query()->orderBy('created_at')->first()
                : Organization::query()->whereKey($id)->first();
        });
    }

    private function property(): ?Property
    {
        $id = $this->option('property');

        if ($id !== null) {
            return Property::query()->whereKey($id)->first();
        }

        return Property::query()->where('status', 'active')->orderBy('created_at')->first();
    }
}
