<?php

declare(strict_types=1);

namespace Tests\Feature\Agents;

use Anthropic\Client;
use Anthropic\RequestOptions;
use App\Domain\Agents\DataObjects\AgentBrief;
use App\Domain\Integrations\DataObjects\AIMessageContext;
use App\Domain\Integrations\Exceptions\AIProviderUnavailableException;
use App\Domain\Integrations\Providers\AI\ClaudeAIProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Tests\Support\RecordingTransporter;
use Tests\TestCase;

/**
 * The live Claude provider, tested on the request it builds.
 *
 * No network call happens here: the SDK's transporter is replaced with one that
 * records. That is deliberate rather than a compromise. What can go wrong in this
 * class is all in the shape of the request — whether the facts carry a cache
 * breakpoint, whether the schema constrains the intent to the categories the
 * agent gates on, whether a transcript that opens with a host message is
 * repaired — and none of it is observable from a mocked provider or from a live
 * call that happens to return something plausible.
 *
 * What is *not* claimed: that Claude answers well. That is what
 * `php artisan agent:evaluate` is for, against a real key.
 */
class ClaudeAIProviderTest extends TestCase
{
    public function test_it_reports_itself_as_not_live_until_a_key_is_configured(): void
    {
        config()->set('services.anthropic.key', null);

        $provider = $this->app->make(ClaudeAIProvider::class);

        $this->assertFalse($provider->isLive());
        $this->assertStringContainsString('ANTHROPIC_API_KEY', (string) $provider->simulationReason());
    }

    public function test_without_a_key_it_refuses_rather_than_returning_an_empty_draft(): void
    {
        config()->set('services.anthropic.key', null);

        $this->expectException(AIProviderUnavailableException::class);

        $this->app->make(ClaudeAIProvider::class)->draftReply($this->context());
    }

    public function test_a_live_key_makes_it_live_and_leaves_no_simulation_notice(): void
    {
        config()->set('services.anthropic.key', 'sk-ant-test');

        $provider = $this->app->make(ClaudeAIProvider::class);

        $this->assertTrue($provider->isLive());
        $this->assertNull($provider->simulationReason());
    }

    public function test_the_facts_are_sent_as_a_cached_system_block_after_the_instruction(): void
    {
        $transporter = $this->transporter();
        $transporter->willAnswer($transporter->textResponse('The wifi is Alfama-Terrace.'));

        $completion = $this->app->make(ClaudeAIProvider::class)
            ->draftReply($this->context(), 'Answer only from the facts provided.');

        $body = $transporter->bodyOf();

        $this->assertSame('claude-opus-5', $body['model']);
        $this->assertSame(2048, $body['max_tokens']);

        // Two blocks: the volatile instruction first, then the facts with the
        // breakpoint. Caching is a prefix match, so a breakpoint on anything
        // that changes per turn would buy nothing.
        $this->assertCount(2, $body['system']);
        $this->assertStringContainsString('Answer only from the facts provided.', $body['system'][0]['text']);
        $this->assertStringContainsString('Alfama Terrace', $body['system'][1]['text']);
        $this->assertSame(['type' => 'ephemeral'], $body['system'][1]['cache_control']);

        $this->assertSame('The wifi is Alfama-Terrace.', $completion->text);
        $this->assertSame(1200, $completion->promptTokens);
        $this->assertSame(80, $completion->completionTokens);
    }

    public function test_classification_is_constrained_to_the_intents_the_agent_gates_on(): void
    {
        $transporter = $this->transporter();
        $transporter->willAnswer($transporter->textResponse((string) json_encode([
            'intent' => 'amenity',
            'urgency' => 'low',
            'sentiment' => 'neutral',
            'confidence' => 0.93,
            'topics' => ['wifi'],
            'summary' => 'Asking for the wifi name.',
        ])));

        $classification = $this->app->make(ClaudeAIProvider::class)->classify($this->context());

        $schema = $transporter->bodyOf()['output_config']['format'];

        $this->assertSame('json_schema', $schema['type']);
        // The enum is the gate's own list. If a category is added to the agent
        // and not to the model's options, the intent silently becomes `other`
        // and a real question is held for no stated reason.
        $this->assertSame(AgentBrief::intents(), $schema['schema']['properties']['intent']['enum']);

        $this->assertSame('amenity', $classification->intent);
        $this->assertSame(0.93, $classification->confidence);
    }

    public function test_an_answer_that_is_not_the_json_it_promised_holds_the_message(): void
    {
        $transporter = $this->transporter();
        $transporter->willAnswer($transporter->textResponse('I think they want the wifi.'));

        $classification = $this->app->make(ClaudeAIProvider::class)->classify($this->context());

        // Zero confidence and `other` both hold the draft, which is the right
        // outcome for an answer nobody can read.
        $this->assertSame(AgentBrief::INTENT_OTHER, $classification->intent);
        $this->assertSame(0.0, $classification->confidence);
    }

    public function test_a_transcript_that_opens_with_the_host_is_repaired(): void
    {
        $transporter = $this->transporter();
        $transporter->willAnswer($transporter->textResponse('Fine.'));

        $this->app->make(ClaudeAIProvider::class)->draftReply(new AIMessageContext(
            messages: [
                ['role' => 'host', 'body' => 'Welcome! Let us know if you need anything.'],
                ['role' => 'guest', 'body' => 'What is the wifi called?'],
            ],
        ));

        $messages = $transporter->bodyOf()['messages'];

        // The API requires the first message to be from the user, and a thread
        // whose first entry is an automated welcome is the common case, not an
        // edge one.
        $this->assertSame('user', $messages[0]['role']);
        $this->assertCount(1, $messages);
    }

    public function test_a_transport_failure_is_reported_as_the_provider_being_unavailable(): void
    {
        $this->bindClient(new class implements ClientInterface
        {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new class extends \RuntimeException implements ClientExceptionInterface
                {
                    protected $message = 'Connection refused';
                };
            }
        });

        $this->expectException(AIProviderUnavailableException::class);
        $this->expectExceptionMessageMatches('/Claude could not be reached/');

        $this->app->make(ClaudeAIProvider::class)->draftReply($this->context());
    }

    public function test_an_organization_level_key_says_which_workspace_to_bill(): void
    {
        $transporter = $this->transporter();
        $transporter->willAnswer($transporter->textResponse('Hello.'));
        config()->set('services.anthropic.workspace', 'wrkspc_demo');

        $this->app->make(ClaudeAIProvider::class)->draftReply($this->context());

        $this->assertSame(
            'wrkspc_demo',
            $transporter->requests[0]->getHeaderLine('anthropic-workspace-id'),
        );
    }

    public function test_a_workspace_scoped_key_sends_no_such_header(): void
    {
        $transporter = $this->transporter();
        $transporter->willAnswer($transporter->textResponse('Hello.'));
        config()->set('services.anthropic.workspace', null);

        $this->app->make(ClaudeAIProvider::class)->draftReply($this->context());

        // Sending it empty would be worse than not sending it: the API would
        // reject a key that is already correctly scoped.
        $this->assertFalse($transporter->requests[0]->hasHeader('anthropic-workspace-id'));
    }

    /**
     * @return list<array{0: string, 1: callable(ClaudeAIProvider): mixed}>
     */
    public static function schemaCalls(): array
    {
        return [
            'classification' => ['classify', fn (ClaudeAIProvider $p) => $p->classify(new AIMessageContext(
                messages: [['role' => 'guest', 'body' => 'Is there a kettle?']],
            ))],
            'review analysis' => ['analyseReview', fn (ClaudeAIProvider $p) => $p->analyseReview('Lovely flat.', 5)],
        ];
    }

    /**
     * Structured outputs accepts a subset of JSON Schema, and rejects the whole
     * request when it meets a keyword outside it — a 400 at runtime, on the
     * deployment, for a schema that looks perfectly valid in an editor. This
     * caught `minimum`/`maximum` on the confidence field in production, so it is
     * asserted on every schema rather than fixed once.
     *
     * @param  callable(ClaudeAIProvider): mixed  $call
     */
    #[DataProvider('schemaCalls')]
    public function test_the_schema_uses_only_keywords_structured_outputs_supports(string $_name, callable $call): void
    {
        $transporter = $this->transporter();
        $transporter->willAnswer($transporter->textResponse('{}'));

        $call($this->app->make(ClaudeAIProvider::class));

        $schema = $transporter->bodyOf()['output_config']['format']['schema'] ?? null;

        $this->assertIsArray($schema);
        $this->assertSame([], $this->unsupportedKeywordsIn($schema));
        // Required on every object, or the request is refused for that instead.
        $this->assertFalse($schema['additionalProperties']);
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    private function unsupportedKeywordsIn(array $schema, string $path = 'schema'): array
    {
        // The documented exclusions: numerical constraints, string length
        // constraints, and complex array constraints.
        $unsupported = [
            'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf',
            'minLength', 'maxLength', 'pattern',
            'minItems', 'maxItems', 'uniqueItems',
        ];

        $found = [];

        foreach ($schema as $key => $value) {
            if (is_string($key) && in_array($key, $unsupported, true)) {
                $found[] = $path.'.'.$key;
            }

            if (is_array($value)) {
                $found = [...$found, ...$this->unsupportedKeywordsIn($value, $path.'.'.(string) $key)];
            }
        }

        return $found;
    }

    public function test_a_confidence_outside_the_range_is_brought_back_into_it(): void
    {
        $transporter = $this->transporter();
        $transporter->willAnswer($transporter->textResponse((string) json_encode([
            'intent' => 'amenity',
            'urgency' => 'low',
            'sentiment' => 'neutral',
            // The schema can no longer constrain this, so the code must.
            'confidence' => 1.4,
            'topics' => [],
            'summary' => '',
        ])));

        $classification = $this->app->make(ClaudeAIProvider::class)->classify($this->context());

        // Otherwise "more certain than certain" would clear every property's
        // confidence floor by construction.
        $this->assertSame(1.0, $classification->confidence);
    }

    public function test_a_confidence_that_is_not_a_number_holds_the_draft(): void
    {
        $transporter = $this->transporter();
        $transporter->willAnswer($transporter->textResponse((string) json_encode([
            'intent' => 'amenity',
            'confidence' => 'very sure',
        ])));

        $classification = $this->app->make(ClaudeAIProvider::class)->classify($this->context());

        $this->assertSame(0.0, $classification->confidence);
    }

    private function context(): AIMessageContext
    {
        return new AIMessageContext(
            messages: [['role' => 'guest', 'body' => 'What is the wifi called?']],
            reservation: ['confirmation_code' => 'HB-000001', 'status' => 'confirmed'],
            property: ['name' => 'Alfama Terrace Apartment', 'city' => 'Lisbon'],
            guestName: 'Ana Ferreira',
            guestLanguage: 'en',
        );
    }

    private function transporter(): RecordingTransporter
    {
        $transporter = new RecordingTransporter;

        $this->bindClient($transporter);

        return $transporter;
    }

    private function bindClient(ClientInterface $transporter): void
    {
        config()->set('services.anthropic.key', 'sk-ant-test');
        config()->set('services.anthropic.model', 'claude-opus-5');

        $this->app->instance(Client::class, new Client(
            apiKey: 'sk-ant-test',
            requestOptions: RequestOptions::with(maxRetries: 0, transporter: $transporter),
        ));
    }
}
