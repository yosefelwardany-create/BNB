<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Integrations\Contracts\AIProviderInterface;
use App\Domain\Integrations\DataObjects\AICompletion;
use App\Domain\Integrations\DataObjects\AIMessageContext;
use App\Domain\Integrations\Exceptions\AIProviderUnavailableException;
use App\Domain\Integrations\Registries\AIProviderRegistry;
use App\Domain\Integrations\Support\ModelPrice;
use Illuminate\Console\Command;

/**
 * Make one real call to the configured AI provider and report what it cost.
 *
 * Three questions this answers that nothing else does. Does the key work — not
 * "is a key set", which {@see AIProviderInterface::isLive()} already tells you,
 * but does the provider accept it. What does one call actually cost, measured
 * rather than estimated from a price list and a guess at the token count. And
 * did the cache breakpoint on the property facts take effect, which is invisible
 * from the request and reported as zero when it silently did not.
 *
 * It is deliberately the smallest useful call: a short prompt, a short reply. A
 * check that cost real money at the scale of the thing being checked would not
 * get run.
 */
class CheckAIProvider extends Command
{
    protected $signature = 'ai:check
        {--force : Do not ask before spending anything}
        {--json : Emit machine-readable output}';

    protected $description = 'Send one real request to the configured AI provider and report the tokens and cost';

    public function handle(AIProviderRegistry $registry): int
    {
        $provider = $registry->default();

        if (! $provider->isLive()) {
            // Not a failure. A deployment running the local simulation is a
            // supported state, and the reason is the actionable part.
            $this->components->warn(sprintf(
                "%s is not live, so nothing was sent and nothing was spent.\n%s",
                $provider->displayName(),
                (string) $provider->simulationReason(),
            ));

            if ($this->option('json')) {
                $this->line((string) json_encode([
                    'provider' => $provider->key(),
                    'is_live' => false,
                    'simulation_reason' => $provider->simulationReason(),
                    'spent' => false,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }

            // Zero, because "the provider is off" is the correct answer to the
            // question asked, not a fault to fail a pipeline on.
            return self::SUCCESS;
        }

        if (! $this->confirmSpending($provider)) {
            $this->components->info('Nothing sent.');

            return self::SUCCESS;
        }

        try {
            $completion = $provider->draftReply($this->probe(), self::INSTRUCTION);
        } catch (AIProviderUnavailableException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $price = ModelPrice::forModel($completion->model);

        $report = [
            'provider' => $provider->key(),
            'is_live' => true,
            'model' => $completion->model,
            'prompt_tokens' => $completion->promptTokens,
            'completion_tokens' => $completion->completionTokens,
            'cache_write_tokens' => $completion->cacheWriteTokens,
            'cache_read_tokens' => $completion->cacheReadTokens,
            'cost_usd' => $price?->costOf($completion),
            'reply' => $completion->text,
            'spent' => true,
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->report($provider, $completion, $price);

        return self::SUCCESS;
    }

    /**
     * The one thing this command spends money on.
     *
     * A real question about a real-shaped property, so the token counts mean
     * something: the same fact block and the same instruction the guest agent
     * sends, just short.
     */
    private function probe(): AIMessageContext
    {
        return new AIMessageContext(
            messages: [['role' => 'guest', 'body' => 'Hello! Is there a kettle in the kitchen?']],
            reservation: ['status' => 'confirmed', 'nights' => 3],
            property: [
                'name' => 'Configuration check',
                'amenities' => ['kettle', 'wifi', 'washing machine'],
            ],
            guestLanguage: 'en',
        );
    }

    private const INSTRUCTION = 'Reply to the guest in one short sentence, using only the facts given.';

    private function confirmSpending(AIProviderInterface $provider): bool
    {
        if ($this->option('force')) {
            return true;
        }

        $price = ModelPrice::forModel((string) config('services.anthropic.model'));

        $cost = $price === null
            ? 'The cost is small but not known here, because there is no price on file for this model.'
            : sprintf(
                'At %s per million input tokens this should cost well under a cent.',
                $price->format($price->inputPerMillion),
            );

        return $this->components->confirm(
            sprintf('This sends one real request to %s. %s Continue?', $provider->displayName(), $cost),
            true,
        );
    }

    private function report(
        AIProviderInterface $provider,
        AICompletion $completion,
        ?ModelPrice $price,
    ): void {
        $this->components->info(sprintf(
            '%s answered. The key works.',
            $provider->displayName(),
        ));

        $this->newLine();
        $this->line('  <fg=gray>It said:</> '.$completion->text);
        $this->newLine();

        $rows = [
            ['Model', (string) ($completion->model ?? 'not reported')],
            ['Prompt tokens', (string) $completion->promptTokens],
            ['Reply tokens', (string) $completion->completionTokens],
        ];

        if ($price === null) {
            $rows[] = ['Cost', 'No price on file for this model'];
        } else {
            $cost = $price->costOf($completion);

            $rows[] = ['Cost of this call', $price->format($cost)];
            // The number an operator actually wants: not what one call cost but
            // what a month of them will.
            $rows[] = ['1,000 calls of this size', $price->format($cost * 1000)];
        }

        $this->table(['', ''], $rows);

        $this->cacheNote($completion, $price);
    }

    /**
     * Whether the cache breakpoint did anything, said plainly either way.
     */
    private function cacheNote(
        AICompletion $completion,
        ?ModelPrice $price,
    ): void {
        if ($completion->cacheReadTokens > 0) {
            $this->line(sprintf(
                '  <fg=green>%d prompt tokens were served from the cache.</>',
                $completion->cacheReadTokens,
            ));

            return;
        }

        if ($completion->cacheWriteTokens > 0) {
            $this->line(sprintf(
                '  <fg=gray>%d prompt tokens were written to the cache; the next call on the same '
                .'facts reads them at a tenth of the price.</>',
                $completion->cacheWriteTokens,
            ));

            return;
        }

        $minimum = $price?->cacheMinimumTokens();

        $this->line(
            '  <fg=yellow>Nothing was cached.</> <fg=gray>'
            .($minimum === null
                ? 'This prompt is below whatever this model\'s minimum cacheable length is.'
                : sprintf(
                    'This model caches a prefix of %s tokens or more, and this probe is far shorter. '
                    .'A real property\'s facts may or may not clear that line — the agent\'s drafts '
                    .'report the same two numbers, so check one of those rather than this.',
                    number_format($minimum),
                ))
            .'</>',
        );
    }
}
