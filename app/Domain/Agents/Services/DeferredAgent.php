<?php

declare(strict_types=1);

namespace App\Domain\Agents\Services;

use App\Domain\Agents\DataObjects\AgentBrief;
use App\Domain\Agents\Enums\AgentAudience;
use App\Domain\Agents\Exceptions\AgentNotConfiguredException;
use App\Domain\Agents\Jobs\DispatchAgentAsk;
use App\Domain\Agents\Models\AgentAsk;
use App\Domain\Agents\Support\BotEndpoint;
use App\Domain\Agents\Support\CallbackToken;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Properties\Models\Property;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Users\Models\User;
use Carbon\CarbonImmutable;

/**
 * Asking a property's agent something it will answer later.
 *
 * The synchronous path holds the HTTP request open while a bot thinks. That is
 * right for a bot that answers in two seconds and wrong for an agent run that
 * reads a repository, checks a calendar and takes two minutes: the client gives
 * up, the operator watches a spinner, and the work the agent did is discarded
 * because nothing was still listening.
 *
 * So the question is written down first. Habitat fires a webhook carrying the
 * question, the facts the asker is entitled to, and a callback URL; then it
 * stops waiting. The agent answers whenever it is finished, against the row that
 * was already there.
 *
 * ## Why this is not an AI provider
 *
 * It would have been tidier to make this another `AIProviderInterface` and reuse
 * everything. It would also have been a lie. `draftReply()` returns a completion,
 * and an implementation that cannot produce one has two options: block until the
 * answer arrives, which defeats the entire point, or return something empty
 * dressed as a completion, which is a fake answer in a system whose central
 * promise is that it never fakes one. An interface that cannot be honestly
 * implemented is the wrong interface.
 *
 * ## What the gates do here
 *
 * The same four, run at the two moments each of them belongs to. Entitlement is
 * decided when the question is asked — {@see PropertyKnowledge} against the
 * booking as it stands then — because that is what determines the facts that go
 * out in the webhook, and they go out immediately. The other three run when the
 * answer comes back, against the brief as it stands *then*: an operator who
 * tightens the confidence floor while an ask is in flight meant it to apply, and
 * the alternative is a policy change that silently skips everything already in
 * the air.
 */
class DeferredAgent
{
    public function __construct(
        private readonly AgentBriefStore $briefs,
        private readonly PropertyKnowledge $knowledge,
        private readonly AgentGates $gates,
        private readonly OperatorKnowledge $operator,
    ) {}

    /**
     * Record a question and fire the webhook that will answer it.
     *
     * Returns as soon as the row exists. The webhook goes out from a queued job,
     * so an endpoint that is slow to even accept the request does not hold up the
     * person who asked.
     *
     * @param  list<array{role: string, body: string}>  $history
     */
    public function ask(
        Property $property,
        string $question,
        ?Reservation $reservation = null,
        array $history = [],
        ?string $guestName = null,
        ?User $asker = null,
        ?Conversation $conversation = null,
        AgentAudience $audience = AgentAudience::Guest,
    ): AgentAsk {
        $brief = $this->briefs->for($property);

        if ($brief->webhookUrl === null) {
            throw new AgentNotConfiguredException(
                'This property has no webhook, so there is nothing to fire. Add one in the agent settings — '
                .'it is the URL that starts your bot, and it is separate from the bot\'s own endpoint.',
            );
        }

        // Re-checked at the moment of use, not only when it was saved: a name
        // that was public when somebody typed it can point somewhere else now.
        $endpoint = BotEndpoint::parse($brief->webhookUrl);

        $token = CallbackToken::issue();

        $ask = new AgentAsk([
            'property_id' => $property->getKey(),
            'reservation_id' => $reservation?->getKey(),
            'conversation_id' => $conversation?->getKey(),
            'asked_by_id' => $asker?->getKey(),
            'audience' => $audience,
            'question' => trim($question),
            'history' => array_values($history),
            'guest_name' => $guestName,
            'callback_token_hash' => $token->hash,
            'expires_at' => CarbonImmutable::now()->addMinutes($this->window()),
            'bot_name' => $brief->botName,
            'endpoint_host' => $endpoint->host,
        ]);

        $ask->save();

        /*
         * The plain token travels in the job and nowhere else.
         *
         * It is never written down — only its hash is — so it has to reach the
         * webhook some other way, and the job payload is that way. The job is
         * encrypted for exactly this reason; see its class docblock.
         */
        DispatchAgentAsk::dispatch($ask->getKey(), $token->plain, (string) $ask->organization_id);

        return $ask;
    }

    /**
     * Take an answer for a pending ask, by the token that was sent with it.
     *
     * Returns null when no open ask matches — an expired one, an answered one, or
     * a token that was never issued. The caller cannot tell those apart from the
     * outside, and should not: distinguishing "wrong token" from "right token,
     * too late" tells somebody guessing which half they have right.
     *
     * @param  array{reply?: mixed, intent?: mixed, confidence?: mixed, error?: mixed}  $payload
     */
    public function receive(string $plainToken, array $payload): ?AgentAsk
    {
        $ask = $this->open($plainToken);

        if ($ask === null) {
            return null;
        }

        $failure = $this->stringOrNull($payload['error'] ?? null);
        $reply = $this->stringOrNull($payload['reply'] ?? $payload['text'] ?? $payload['message'] ?? null);

        // A bot saying it could not answer is an outcome, not a malformed
        // request. It is recorded as one and the ask is closed, because the
        // token has been spent either way.
        if ($reply === null) {
            $ask->forceFill([
                'status' => AgentAsk::STATUS_FAILED,
                'failure' => $failure ?? 'The bot answered without any reply text.',
                'answered_at' => CarbonImmutable::now(),
            ])->save();

            return $ask;
        }

        $brief = $this->briefs->for($ask->property);

        $intent = $this->gates->normaliseIntent(
            is_string($payload['intent'] ?? null) ? $payload['intent'] : AgentBrief::INTENT_OTHER,
        );

        // Absent is zero, never a guess. Zero is below every floor, so an answer
        // whose certainty nobody stated waits for a person — which is the honest
        // reading of "nobody said".
        $confidence = is_numeric($payload['confidence'] ?? null)
            ? max(0.0, min(1.0, (float) $payload['confidence']))
            : 0.0;

        // Nothing an operator asked for goes to a guest, so there is no second
        // party for it to reach unread and the auto-send gates have nothing to
        // decide. Reporting a "held for review" reason to the person already
        // reading it would be theatre.
        $held = $ask->audience->isSendable()
            ? $this->gates->reasonToHold(
                $brief,
                $intent,
                $ask->question,
                $confidence,
                // Decided when the question was asked, which is when it governed
                // what went out in the webhook.
                $ask->withheld ?? [],
            )
            : null;

        $ask->forceFill([
            'status' => AgentAsk::STATUS_ANSWERED,
            'reply' => $reply,
            'intent' => $intent,
            'confidence' => $confidence,
            'would_auto_send' => $ask->audience->isSendable() && $held === null,
            'held_because' => $held,
            'failure' => $failure,
            'answered_at' => CarbonImmutable::now(),
        ])->save();

        return $ask;
    }

    /**
     * The facts and the stay to send with one ask.
     *
     * Called by the job rather than at ask time so that nothing entitled —
     * a door code, a wifi password — is ever serialised into a queue payload.
     * What the row keeps is the *names* of the facts that went out, which answers
     * "was it entitled to the code" without storing the code a second time.
     *
     * @return array{facts: array<string, mixed>, stay: array<string, mixed>, withheld: list<string>}
     */
    public function entitlement(AgentAsk $ask): array
    {
        $property = $ask->property;

        /*
         * Which rules apply depends on who asked, and the asker is recorded on
         * the row rather than re-derived here. An operator's permissions are
         * read as they stood when they asked — they are not signed in by the
         * time this runs, and a role changed in the meantime should not silently
         * widen or narrow a question already in flight.
         */
        if ($ask->audience === AgentAudience::Operator) {
            $assembled = $this->operator->about($property, $ask->askedBy);

            return [
                'facts' => $assembled['facts'],
                'stay' => [],
                'withheld' => $assembled['withheld'],
            ];
        }

        $reservation = $ask->reservation;

        $facts = $this->knowledge->public($property);
        [$arrival, $withheld] = $this->knowledge->arrival($property, $reservation);

        return [
            'facts' => $facts + ($arrival === [] ? [] : ['arrival' => $arrival]),
            'stay' => $this->knowledge->stay($reservation),
            'withheld' => $withheld,
        ];
    }

    /**
     * Close out asks nobody answered in time.
     *
     * A pending ask is a live credential. One left open because a bot was
     * switched off a fortnight ago is a key to a write, held by something nobody
     * is watching — and a row on somebody's screen that has been saying "waiting"
     * since the Tuesday before last.
     *
     * The row is kept, with its question and the reason. Nothing here deletes.
     */
    public function expireLapsed(): int
    {
        return AgentAsk::query()
            ->withoutGlobalScope('organization')
            ->lapsed()
            ->update([
                'status' => AgentAsk::STATUS_EXPIRED,
                'failure' => 'Nothing answered within the time allowed, so the callback was closed.',
                'updated_at' => CarbonImmutable::now(),
            ]);
    }

    /**
     * The one open ask this token answers for, if there is one.
     *
     * Unscoped by tenant deliberately. The callback arrives with no session and
     * no organization — the bot is somebody's agent run on their own
     * infrastructure, and the token is the only thing it carries. The token is
     * what narrows this to one row, and that row carries the organization, which
     * is then used for everything that follows.
     */
    private function open(string $plainToken): ?AgentAsk
    {
        $ask = AgentAsk::query()
            ->withoutGlobalScope('organization')
            ->with(['property', 'reservation.property', 'askedBy'])
            ->where('callback_token_hash', CallbackToken::hash($plainToken))
            ->first();

        // `dispatched_at` guards against answering a question that was never
        // asked: a row exists from the moment the token is issued, and until the
        // job has actually sent it, no bot can legitimately have it.
        return $ask !== null && $ask->isOpen() && $ask->dispatched_at !== null
            ? $ask
            : null;
    }

    /**
     * How long an ask stays answerable, in minutes.
     */
    public function window(): int
    {
        return max(1, (int) config('pms.agents.webhook.window_minutes', 30));
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
