<?php

declare(strict_types=1);

namespace App\Domain\Properties\Events;

use App\Domain\Events\Support\AbstractDomainEvent;
use App\Domain\Properties\Models\Property;
use Illuminate\Database\Eloquent\Model;

/**
 * The property is now on the market. Channel publication, availability
 * seeding and onboarding automations all hang off this.
 */
class PropertyActivated extends AbstractDomainEvent
{
    public const NAME = 'property.activated';

    public function __construct(public readonly Property $property)
    {
        parent::__construct();
    }

    public function subject(): ?Model
    {
        return $this->property;
    }

    public function payload(): array
    {
        return [
            'property_id' => $this->property->getKey(),
            'name' => $this->property->name,
            'activated_at' => $this->property->activated_at?->toIso8601String(),
        ];
    }
}
