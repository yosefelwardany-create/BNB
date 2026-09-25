<?php

declare(strict_types=1);

namespace Tests\Feature\Agents;

use Anthropic\Client;
use Anthropic\RequestOptions;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\RecordingTransporter;
use Tests\TestCase;

/**
 * `php artisan ai:check`.
 *
 * The test that matters most here is the one asserting **nothing was sent** when
 * no key is configured. A command whose job is to spend a little money must not
 * spend any when it has been told the provider is off, and "it printed a warning"
 * is not the same claim as "it made no request".
 *
 * Assertions read the command's output through `Artisan::output()` rather than
 * `expectsOutputToContain`, which matches one expectation per write: the JSON
 * report is a single write, so a second expectation against it silently never
 * matches and the test fails for a reason that has nothing to do with the
 * command.
 */
class CheckAIProviderTest extends TestCase
{
    public function test_without_a_key_it_sends_nothing_and_says_why(): void
    {
        $transporter = $this->transporter(live: false);

        $this->assertSame(0, Artisan::call('ai:check', ['--force' => true]));
        $this->assertSame([], $transporter->requests, 'Nothing may be sent when no key is configured.');
        $this->assertStringContainsString('ANTHROPIC_API_KEY', Artisan::output());
    }

    public function test_the_off_state_is_not_reported_as_a_failure(): void
    {
        // A deployment running the local simulation is supported, so a pipeline
        // calling this must not go red over it.
        $this->transporter(live: false);

        $exit = Artisan::call('ai:check', ['--force' => true, '--json' => true]);
        $report = $this->jsonOf(Artisan::output());

        $this->assertSame(0, $exit);
        $this->assertFalse($report['is_live']);
        $this->assertFalse($report['spent']);
        $this->assertNotNull($report['simulation_reason']);
    }

    public function test_it_reports_the_tokens_and_the_cost_of_one_real_call(): void
    {
        $transporter = $this->answering(['input_tokens' => 400, 'output_tokens' => 120]);

        Artisan::call('ai:check', ['--force' => true, '--json' => true]);
        $report = $this->jsonOf(Artisan::output());

        $this->assertTrue($report['is_live']);
        $this->assertTrue($report['spent']);
        $this->assertSame('claude-haiku-4-5', $report['model']);
        $this->assertSame(400, $report['prompt_tokens']);
        $this->assertSame(120, $report['completion_tokens']);
        // 400 in at $1/M plus 120 out at $5/M.
        $this->assertSame(0.001, round((float) $report['cost_usd'], 6));

        $this->assertCount(1, $transporter->requests, 'It must make exactly one call.');
    }

    public function test_a_model_with_no_recorded_price_is_admitted_rather_than_guessed(): void
    {
        config()->set('services.anthropic.model', 'claude-not-yet-released');
        $this->answering(['input_tokens' => 10, 'output_tokens' => 5]);

        Artisan::call('ai:check', ['--force' => true, '--json' => true]);
        $report = $this->jsonOf(Artisan::output());

        // Null, not zero: a cost report that is confidently wrong is worse than
        // one that admits the gap.
        $this->assertNull($report['cost_usd']);
        $this->assertSame(10, $report['prompt_tokens']);
    }

    public function test_it_says_plainly_when_the_cache_breakpoint_did_nothing(): void
    {
        $this->answering([
            'input_tokens' => 400,
            'output_tokens' => 10,
            // What a prompt below the model's minimum cacheable length reports:
            // no error, no entry, no saving.
            'cache_creation_input_tokens' => 0,
            'cache_read_input_tokens' => 0,
        ]);

        Artisan::call('ai:check', ['--force' => true]);
        $output = Artisan::output();

        $this->assertStringContainsString('Nothing was cached.', $output);
        // Haiku's minimum, named, so nobody concludes the caching code is broken.
        $this->assertStringContainsString('4,096 tokens or more', $output);
    }

    public function test_it_reports_a_cache_hit_when_there_is_one(): void
    {
        $this->answering([
            'input_tokens' => 40,
            'output_tokens' => 10,
            'cache_creation_input_tokens' => 0,
            'cache_read_input_tokens' => 5000,
        ]);

        Artisan::call('ai:check', ['--force' => true, '--json' => true]);
        $report = $this->jsonOf(Artisan::output());

        $this->assertSame(5000, $report['cache_read_tokens']);
        // 5000 cached reads at $0.10/M, 40 uncached at $1/M, 10 out at $5/M.
        // Cached tokens must not also be billed as input.
        $this->assertSame(0.00059, round((float) $report['cost_usd'], 6));
    }

    public function test_it_asks_before_spending_anything(): void
    {
        $transporter = $this->transporter();

        $this->artisan('ai:check')
            ->expectsConfirmation(
                'This sends one real request to Claude. At $1.00 per million input tokens '
                .'this should cost well under a cent. Continue?',
                'no',
            )
            ->assertSuccessful();

        $this->assertSame([], $transporter->requests);
    }

    /**
     * A transporter that will answer one call with the given usage figures.
     *
     * @param  array<string, int>  $usage
     */
    private function answering(array $usage): RecordingTransporter
    {
        $transporter = $this->transporter();

        $transporter->willAnswer([
            ...$transporter->textResponse('Yes, there is a kettle in the kitchen.'),
            'usage' => $usage,
        ]);

        return $transporter;
    }

    private function transporter(bool $live = true): RecordingTransporter
    {
        $transporter = new RecordingTransporter;

        config()->set('pms.providers.ai', 'claude');
        config()->set('services.anthropic.key', $live ? 'sk-ant-test' : null);

        $this->app->instance(Client::class, new Client(
            apiKey: 'sk-ant-test',
            requestOptions: RequestOptions::with(maxRetries: 0, transporter: $transporter),
        ));

        return $transporter;
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonOf(string $output): array
    {
        $start = strpos($output, '{');

        $this->assertNotFalse($start, 'The command printed no JSON.');

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(substr($output, $start), true, flags: JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
