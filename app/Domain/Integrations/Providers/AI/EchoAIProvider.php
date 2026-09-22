<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Providers\AI;

use App\Domain\Integrations\Contracts\AIProviderInterface;
use App\Domain\Integrations\DataObjects\AIClassification;
use App\Domain\Integrations\DataObjects\AICompletion;
use App\Domain\Integrations\DataObjects\AIMessageContext;
use Illuminate\Support\Str;

/**
 * A deterministic, local stand-in for a language model.
 *
 * It uses keyword heuristics rather than a model, which is enough to develop
 * and test the surrounding workflow — drafting, routing, urgency escalation,
 * review analysis — without an external dependency or a per-test API cost.
 *
 * `isLive()` is false and the UI labels its output accordingly, so a manager
 * always knows they are looking at local heuristics rather than a model.
 */
class EchoAIProvider implements AIProviderInterface
{
    /** @var array<string, list<string>> */
    private const URGENCY_KEYWORDS = [
        'critical' => ['emergency', 'fire', 'flood', 'gas', 'break-in', 'broken into', 'no heat', 'no water', 'locked out'],
        'high' => ['urgent', 'asap', 'immediately', 'not working', 'broken', 'leak', 'cannot get in', "can't get in", 'no hot water'],
        'normal' => ['question', 'wondering', 'could you', 'would like'],
    ];

    /** @var array<string, list<string>> */
    private const INTENT_KEYWORDS = [
        'booking_change' => ['cancel', 'reschedule', 'change my dates', 'move my booking', 'extend'],
        'access' => ['check-in', 'checkin', 'code', 'key', 'access', 'door', 'directions', 'address'],
        'maintenance' => ['broken', 'leak', 'not working', 'repair', 'fix', 'clogged', 'wifi'],
        'cleaning' => ['dirty', 'clean', 'towels', 'linen', 'rubbish', 'trash'],
        'billing' => ['refund', 'charge', 'invoice', 'receipt', 'deposit', 'payment'],
        'upsell' => ['early check', 'late check', 'transfer', 'airport', 'extra bed', 'parking'],
        'review' => ['review', 'feedback', 'rating'],
    ];

    /** @var list<string> */
    private const NEGATIVE_WORDS = ['dirty', 'broken', 'terrible', 'awful', 'disappointed', 'rude', 'never again', 'worst', 'unacceptable', 'poor'];

    /** @var list<string> */
    private const POSITIVE_WORDS = ['great', 'excellent', 'lovely', 'perfect', 'amazing', 'wonderful', 'spotless', 'thank you', 'fantastic', 'recommend'];

    public function key(): string
    {
        return 'echo';
    }

    public function displayName(): string
    {
        return 'Local heuristics (development)';
    }

    public function isLive(): bool
    {
        return false;
    }

    public function draftReply(AIMessageContext $context, ?string $instruction = null): AICompletion
    {
        $guestMessage = $context->lastGuestMessage() ?? '';
        $classification = $this->classifyText($guestMessage);
        $name = $context->guestName ? explode(' ', trim($context->guestName))[0] : 'there';

        $body = match ($classification->intent) {
            'access' => sprintf(
                "Hi %s,\n\nHere are your arrival details for %s. Check-in is from %s and the access instructions are in your guest portal.\n\nLet me know if anything is unclear and I will help straight away.",
                $name,
                $context->property['name'] ?? 'your stay',
                $context->reservation['check_in_time'] ?? '3:00 pm',
            ),
            'maintenance' => sprintf(
                "Hi %s,\n\nThank you for letting me know — I am sorry about that. I am arranging for someone to look at it and will confirm a time with you shortly.\n\nApologies for the inconvenience.",
                $name,
            ),
            'cleaning' => sprintf(
                "Hi %s,\n\nThank you for flagging this. I am sending our housekeeping team over to put it right, and I will confirm once they are on their way.",
                $name,
            ),
            'booking_change' => sprintf(
                "Hi %s,\n\nI can look into that for you. Your booking currently runs from %s to %s. Could you confirm the dates you would prefer and I will check availability and any difference in price?",
                $name,
                $context->reservation['check_in'] ?? 'your arrival date',
                $context->reservation['check_out'] ?? 'your departure date',
            ),
            'billing' => sprintf(
                "Hi %s,\n\nThank you for getting in touch. I am reviewing the charges on your booking now and will come back to you with a full breakdown shortly.",
                $name,
            ),
            'upsell' => sprintf(
                "Hi %s,\n\nThat should be possible. I am checking availability now and will confirm the options and cost for you shortly.",
                $name,
            ),
            default => sprintf(
                "Hi %s,\n\nThank you for your message. I am looking into this and will come back to you shortly.",
                $name,
            ),
        };

        if ($instruction !== null && trim($instruction) !== '') {
            $body .= "\n\n(Drafted with the instruction: ".trim($instruction).')';
        }

        return new AICompletion(
            text: $body,
            provider: $this->key(),
            model: 'heuristic-v1',
            promptTokens: (int) ceil(mb_strlen($guestMessage) / 4),
            completionTokens: (int) ceil(mb_strlen($body) / 4),
            finishReason: 'stop',
        );
    }

    public function summarise(AIMessageContext $context): AICompletion
    {
        $count = count($context->messages);
        $guestMessages = array_values(array_filter(
            $context->messages,
            fn (array $m): bool => ($m['role'] ?? '') === 'guest',
        ));

        $topics = [];
        foreach ($guestMessages as $message) {
            $topics = array_merge($topics, $this->classifyText($message['body'] ?? '')->topics);
        }
        $topics = array_values(array_unique($topics));

        $summary = sprintf(
            '%d message%s exchanged%s. %s',
            $count,
            $count === 1 ? '' : 's',
            $context->guestName !== null ? ' with '.$context->guestName : '',
            $topics === []
                ? 'No specific issues were raised.'
                : 'Topics raised: '.implode(', ', $topics).'.',
        );

        $last = $context->lastGuestMessage();

        if ($last !== null) {
            $summary .= ' Most recent guest message: "'.Str::limit($last, 160).'"';
        }

        return new AICompletion(
            text: $summary,
            provider: $this->key(),
            model: 'heuristic-v1',
            finishReason: 'stop',
        );
    }

    public function translate(string $text, string $targetLanguage, ?string $sourceLanguage = null): AICompletion
    {
        // No translation is performed locally, and the output says so rather
        // than returning the original text as though it had been translated.
        return new AICompletion(
            text: sprintf('[%s translation unavailable locally] %s', strtoupper($targetLanguage), $text),
            provider: $this->key(),
            model: 'heuristic-v1',
            finishReason: 'stop',
        );
    }

    public function classify(AIMessageContext $context): AIClassification
    {
        return $this->classifyText($context->lastGuestMessage() ?? '');
    }

    public function analyseReview(string $reviewText, ?int $rating = null): AIClassification
    {
        $classification = $this->classifyText($reviewText);

        // An explicit star rating is a stronger signal than the wording.
        $sentiment = match (true) {
            $rating !== null && $rating <= 2 => 'negative',
            $rating !== null && $rating >= 4 => 'positive',
            $rating !== null => 'neutral',
            default => $classification->sentiment,
        };

        return new AIClassification(
            intent: 'review',
            urgency: $sentiment === 'negative' ? 'high' : 'low',
            sentiment: $sentiment,
            confidence: $rating !== null ? 0.9 : $classification->confidence,
            topics: $classification->topics,
            suggestedTasks: $sentiment === 'negative' ? $classification->suggestedTasks : [],
            summary: Str::limit($reviewText, 200),
            provider: $this->key(),
        );
    }

    private function classifyText(string $text): AIClassification
    {
        $haystack = mb_strtolower($text);

        $urgency = 'low';
        foreach (self::URGENCY_KEYWORDS as $level => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($haystack, $keyword)) {
                    $urgency = $level;
                    break 2;
                }
            }
        }

        $intent = 'general';
        $topics = [];
        foreach (self::INTENT_KEYWORDS as $candidate => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($haystack, $keyword)) {
                    $topics[] = $candidate;

                    if ($intent === 'general') {
                        $intent = $candidate;
                    }

                    break;
                }
            }
        }

        $negative = $this->countMatches($haystack, self::NEGATIVE_WORDS);
        $positive = $this->countMatches($haystack, self::POSITIVE_WORDS);

        $sentiment = match (true) {
            $negative > $positive => 'negative',
            $positive > $negative => 'positive',
            default => 'neutral',
        };

        $suggestedTasks = match ($intent) {
            'maintenance' => ['Raise a maintenance ticket for the reported issue'],
            'cleaning' => ['Schedule a housekeeping visit'],
            'access' => ['Confirm the access code is active for this stay'],
            default => [],
        };

        return new AIClassification(
            intent: $intent,
            urgency: $urgency,
            sentiment: $sentiment,
            // Heuristics are honest about being uncertain: automation
            // thresholds are set above this, so nothing auto-sends on them.
            confidence: $topics === [] ? 0.35 : 0.6,
            topics: array_values(array_unique($topics)),
            suggestedTasks: $suggestedTasks,
            provider: $this->key(),
        );
    }

    /**
     * @param  list<string>  $words
     */
    private function countMatches(string $haystack, array $words): int
    {
        $count = 0;

        foreach ($words as $word) {
            if (str_contains($haystack, $word)) {
                $count++;
            }
        }

        return $count;
    }
}
