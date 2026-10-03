<?php

declare(strict_types=1);

namespace App\Domain\Agents\Jobs;

use App\Domain\Agents\DataObjects\AgentBrief;
use App\Domain\Agents\Enums\AgentAudience;
use App\Domain\Agents\Models\AgentAsk;
use App\Domain\Agents\Services\AgentBriefStore;
use App\Domain\Agents\Services\DeferredAgent;
use App\Domain\Agents\Services\PropertyAgentMemory;
use App\Domain\Agents\Support\BotEndpoint;
use App\Domain\Organization\Models\Organization;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Fires one property's webhook with a question and a way to answer it.
 *
 * Encrypted on the queue, and that is not belt-and-braces. The callback token is
 * stored only as a hash, so the one copy of the plain token in existence between
 * `DeferredAgent::ask()` and the outbound request is the one in this payload. A
 * queue backend is a database or a Redis instance with its own backups and its
 * own access list, and a credential sitting in it in the clear is a credential
 * that has left the place credentials live.
 *
 * The facts are assembled here rather than carried, for the same reason pointed
 * the other way: a door code must not be serialised into a queue payload at all,
 * encrypted or not. They are read from the property when the job runs, against
 * the booking the ask recorded.
 *
 * Two attempts. The failure this retries is a webhook host that did not accept
 * the request, which is usually momentary; the cost of being wrong is that a bot
 * is asked the same question twice, and since the callback token is single-use
 * only one of those answers can land. That is a better trade than an ask that
 * silently never left.
 */
class DispatchAgentAsk implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    /** @var list<int> */
    public array $backoff = [10, 30];

    public function __construct(
        public readonly string $askId,
        public readonly string $callbackToken,
        public readonly string $organizationId,
    ) {
        $this->onQueue(config('pms.queues.ai', 'default'));
    }

    public function handle(
        TenantContext $tenancy,
        AgentBriefStore $briefs,
        DeferredAgent $agent,
    ): void {
        $organization = $tenancy->withoutScope(
            fn (): ?Organization => Organization::query()->find($this->organizationId),
        );

        if ($organization === null) {
            return;
        }

        $tenancy->runAs($organization, function () use ($briefs, $agent): void {
            $ask = AgentAsk::query()->with(['property', 'reservation.property'])->find($this->askId);

            // Already settled: this job ran twice, or the ask was expired by the
            // sweep while it sat on the queue. Sending again would put the same
            // question to somebody's bot a second time.
            if ($ask === null || $ask->status !== AgentAsk::STATUS_PENDING || $ask->dispatched_at !== null) {
                return;
            }

            $brief = $briefs->for($ask->property);

            if ($brief->webhookUrl === null) {
                $this->settle($ask, 'The property\'s webhook was removed before the question could be sent.');

                return;
            }

            $endpoint = BotEndpoint::parse($brief->webhookUrl);
            $entitled = $agent->entitlement($ask);

            /*
             * Written before the request goes out, not after.
             *
             * A bot fast enough to call back while this HTTP call is still
             * returning would otherwise find a row that says it was never
             * dispatched, and be refused its own answer. Recording the intent
             * first makes the ordering impossible to get wrong; if the request
             * then fails, the failure path below closes the ask anyway.
             */
            $ask->forceFill([
                // Names, never values. The door code travels in the request and
                // is not written down a second time.
                'sent_fact_keys' => array_keys($entitled['facts']),
                'withheld' => $entitled['withheld'],
                'dispatched_at' => CarbonImmutable::now(),
            ])->save();

            try {
                $response = Http::asJson()
                    ->acceptJson()
                    ->withHeaders(array_filter([
                        'Authorization' => $ask->property->agent_webhook_token === null
                            ? null
                            : 'Bearer '.$ask->property->agent_webhook_token,
                        'X-Habitat-Property' => (string) $ask->property_id,
                        'X-Habitat-Ask' => (string) $ask->getKey(),
                    ]))
                    ->timeout((int) config('pms.agents.webhook.timeout', 10))
                    ->post($endpoint->url, $this->payload($ask, $entitled, $agent));
            } catch (ConnectionException $e) {
                // Rethrown so the queue's one retry applies. `failed()` settles
                // the row if the second attempt goes the same way.
                throw new ConnectionException(sprintf(
                    'The webhook at %s could not be reached: %s',
                    $endpoint->host,
                    $e->getMessage(),
                ), $e->getCode(), $e);
            }

            if ($response->failed()) {
                // Its own words. A 401 here almost always means the sender key
                // is wrong, and saying so beats "the webhook failed".
                $this->settle($ask, sprintf(
                    'The webhook at %s answered %d. %s',
                    $endpoint->host,
                    $response->status(),
                    mb_substr(trim($response->body()), 0, 300) ?: 'It said nothing further.',
                ));
            }
        });
    }

    /**
     * What the bot is sent.
     *
     * Deliberately the same shape as the synchronous bot's request, plus the
     * callback block, so one endpoint can serve both and an operator who has
     * already written a bridge for one has nothing new to learn.
     *
     * `prompt` is the same content again as plain text, and it is not
     * redundancy. The endpoint on the other side is often not code somebody
     * wrote for this — it is an automation platform that takes a webhook and
     * starts an agent from it, and those overwhelmingly look for a prompt string
     * rather than parsing an unfamiliar schema. A structured field nobody reads
     * is a feature that silently never answers, so the instruction that makes
     * the round trip work is stated where a general-purpose agent will actually
     * meet it: in prose, with the exact request to make.
     *
     * @param  array{facts: array<string, mixed>, stay: array<string, mixed>, withheld: list<string>}  $entitled
     * @return array<string, mixed>
     */
    private function payload(AgentAsk $ask, array $entitled, DeferredAgent $agent): array
    {
        $callback = route('api.public.agent-callback', ['token' => $this->callbackToken]);

        return [
            'prompt' => $this->prompt($ask, $entitled, $callback),
            'question' => $ask->question,
            'history' => $ask->history ?? [],
            'property' => array_filter([
                'id' => (string) $ask->property_id,
                'name' => $ask->property->name,
                'city' => $ask->property->city,
                'timezone' => $ask->property->timezone,
            ], static fn (mixed $value): bool => $value !== null),
            'facts' => $entitled['facts'],
            'stay' => $entitled['stay'] === [] ? null : $entitled['stay'],
            'guest' => ['name' => $ask->guest_name],
            // Said out loud rather than left to be inferred from absence, so a
            // bot can explain why it is not sharing a door code instead of
            // guessing one.
            'withheld' => $entitled['withheld'],

            /*
             * How to answer. The whole reason this request can be fire and
             * forget.
             */
            'callback' => [
                'url' => $callback,
                'method' => 'POST',
                'expires_at' => $ask->expires_at?->toIso8601String(),
                'expires_in_minutes' => $agent->window(),
                'body' => [
                    'reply' => '<what to say>',
                    'intent' => '<one of: amenity, directions, house_rules, local_recommendation, '
                        .'access, booking_change, payment, complaint, other>',
                    'confidence' => '<0.0-1.0, how sure you are>',
                ],
                'note' => 'The URL is the credential and it works once. Confidence decides whether a '
                    .'person reads the answer before a guest does, so report what you actually believe: '
                    .'omitting it holds the answer for review, which is the right outcome when unsure.',
            ],
        ];
    }

    /**
     * The whole ask as something a general-purpose agent can act on.
     *
     * Written for an agent that was handed this webhook body and nothing else.
     * It therefore says what the facts are, what it must not do, and — last,
     * where an instruction is most likely to be followed — the exact request
     * that delivers the answer.
     *
     * The limits are repeated here in prose because this is the copy some agents
     * will read instead of the structured fields. Repeating them costs a few
     * hundred characters. Omitting them means the only statement of "do not
     * invent a door code" is in a key the agent never looked at.
     *
     * @param  array{facts: array<string, mixed>, stay: array<string, mixed>, withheld: list<string>}  $entitled
     */
    private function prompt(AgentAsk $ask, array $entitled, string $callback): string
    {
        $json = static fn (mixed $value): string => (string) json_encode(
            $value,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        $operator = $ask->audience === AgentAudience::Operator;

        $lines = [
            $operator ? PropertyAgentMemory::instruction() : '',
            $operator
                // Said first, because everything else follows from it. An agent
                // that thinks it is talking to a guest hedges, apologises and
                // declines to quote a figure — the opposite of useful to the
                // person who owns the flat.
                ? sprintf(
                    'You are answering the manager of %s about their own property. They are NOT a guest — '
                    .'they own or run this place and are asking how it is doing. Give them the figures '
                    .'plainly, with their currency, and do not round them into vagueness.',
                    $ask->property->name,
                )
                : sprintf(
                    'You are answering a guest\'s question about %s, a short-let property managed in Habitat.',
                    $ask->property->name,
                ),
            '',
            'QUESTION: '.$ask->question,
            '',
            $operator
                ? 'WHAT YOU KNOW ABOUT THIS PROPERTY (these figures are everything you have; do not estimate any others):'
                : 'FACTS YOU MAY USE (these are everything you are allowed to know):',
            $json($entitled['facts']),
        ];

        if ($entitled['stay'] !== []) {
            $lines[] = '';
            $lines[] = 'THE BOOKING THIS CONCERNS:';
            $lines[] = $json($entitled['stay']);
        }

        if ($entitled['withheld'] !== [] && $operator) {
            $lines[] = '';
            $lines[] = 'SOME FIGURES ARE DELIBERATELY NOT INCLUDED: '
                .implode(' ', $entitled['withheld'])
                .' Say which ones you cannot see rather than working around the gap.';
        } elseif ($entitled['withheld'] !== []) {
            $lines[] = '';
            // Stated rather than left to be inferred from a missing key, so the
            // answer can explain the gap instead of filling it.
            $lines[] = 'ARRIVAL DETAILS ARE DELIBERATELY NOT INCLUDED, because: '
                .implode(' ', $entitled['withheld'])
                .' Say someone will send them, and why. Do not guess a door code, a wifi password or an address.';
        }

        if (($ask->history ?? []) !== []) {
            $lines[] = '';
            $lines[] = 'EARLIER IN THIS CONVERSATION:';
            $lines[] = $json($ask->history);
        }

        $lines[] = '';
        $lines[] = $operator
            ? 'Answer only from the figures above. If one is missing, say which — never estimate it, and '
                .'never read a trend from a single window. Where a number looks surprising, say what would '
                .'explain it rather than asserting a cause.'
            : 'Answer only from the facts above. If they do not cover the question, say someone will '
                .'follow up. Do not offer a refund, a discount or a date change.';
        $lines[] = '';
        $lines[] = 'WHEN YOU HAVE AN ANSWER, SEND IT BACK. Nothing is waiting on this request, so the answer '
            .'is lost unless you make this one HTTP call:';
        $lines[] = '';
        $lines[] = sprintf(
            "curl -X POST '%s' \\\n  -H 'Content-Type: application/json' \\\n"
            ."  -d '{\"reply\": \"<your answer>\", \"intent\": \"<one of: %s>\", \"confidence\": <0.0-1.0>}'",
            $callback,
            implode(', ', AgentBrief::intents()),
        );
        $minutes = $ask->expires_at === null
            ? 30
            : max(1, (int) round(CarbonImmutable::now()->diffInMinutes($ask->expires_at)));

        $lines[] = '';
        $lines[] = sprintf(
            'That URL is the credential — it needs no key of its own, it works once, and it stops working '
            .'in %d minutes. Report confidence honestly: below the property\'s floor a person reads your '
            .'answer before the guest does, which is the right outcome when you are unsure. If you cannot '
            .'answer, post {"error": "<why>"} to the same URL so somebody is told rather than left waiting.',
            $minutes,
        );

        return implode("\n", $lines);
    }

    /**
     * Close an ask that is not going to be answered, keeping why.
     */
    private function settle(AgentAsk $ask, string $failure): void
    {
        $ask->forceFill([
            'status' => AgentAsk::STATUS_FAILED,
            'failure' => $failure,
            'answered_at' => CarbonImmutable::now(),
        ])->save();
    }

    /**
     * A job that gave up still settles its ask.
     *
     * Otherwise a webhook host that is down leaves a row saying "waiting" until
     * the expiry sweep gets to it, which is half an hour of a screen implying
     * somebody's bot is thinking about it.
     */
    public function failed(?Throwable $exception): void
    {
        AgentAsk::query()
            ->withoutGlobalScope('organization')
            ->whereKey($this->askId)
            ->where('status', AgentAsk::STATUS_PENDING)
            ->update([
                'status' => AgentAsk::STATUS_FAILED,
                'failure' => mb_substr(
                    $exception?->getMessage() ?? 'The question could not be sent.',
                    0,
                    300,
                ),
                'answered_at' => CarbonImmutable::now(),
                'updated_at' => CarbonImmutable::now(),
            ]);
    }
}
