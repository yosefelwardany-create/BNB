<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Agents\Enums\AgentCapability;
use App\Domain\Agents\Models\AgentAction;
use App\Domain\Properties\Models\Property;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentAction>
 */
class AgentActionFactory extends Factory
{
    protected $model = AgentAction::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            // The harmless one, so a test that does not care what the action is
            // cannot accidentally be testing a cancellation.
            'capability' => AgentCapability::AddNote,
            'arguments' => ['body' => 'The lift is out until March.'],
            'summary' => 'Leave a note about the lift.',
            'status' => AgentAction::STATUS_PROPOSED,
            'was_autonomous' => false,
            'expires_at' => CarbonImmutable::now()->addHours(48),
        ];
    }

    /**
     * Past its window: still `proposed`, and no longer approvable.
     */
    public function lapsed(): self
    {
        return $this->state(fn (): array => [
            'expires_at' => CarbonImmutable::now()->subHour(),
        ]);
    }
}
