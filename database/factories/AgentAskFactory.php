<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Agents\Models\AgentAsk;
use App\Domain\Agents\Support\CallbackToken;
use App\Domain\Properties\Models\Property;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentAsk>
 */
class AgentAskFactory extends Factory
{
    protected $model = AgentAsk::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'status' => AgentAsk::STATUS_PENDING,
            'question' => 'Is there a lift to the third floor?',
            'history' => [],
            'callback_token_hash' => CallbackToken::issue()->hash,
            'expires_at' => CarbonImmutable::now()->addMinutes(30),
            'bot_name' => 'Yellow',
            'endpoint_host' => 'bots.example.test',
            // Pending but not yet sent is the state a row is in for the moment
            // between being written and the job running, and no callback may be
            // accepted in it. Factories default to the ordinary case: sent.
            'dispatched_at' => CarbonImmutable::now(),
        ];
    }

    public function answered(): self
    {
        return $this->state(fn (): array => [
            'status' => AgentAsk::STATUS_ANSWERED,
            'reply' => 'Yes — there is a lift from the lobby to every floor.',
            'intent' => 'amenity',
            'confidence' => 0.9,
            'would_auto_send' => false,
            'held_because' => 'This property has not enabled sending amenity answers on their own.',
            'answered_at' => CarbonImmutable::now(),
        ]);
    }

    public function lapsed(): self
    {
        return $this->state(fn (): array => [
            'expires_at' => CarbonImmutable::now()->subMinute(),
        ]);
    }
}
