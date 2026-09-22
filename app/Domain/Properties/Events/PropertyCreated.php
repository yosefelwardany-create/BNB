<?php

declare(strict_types=1);

namespace App\Domain\Properties\Events;

use App\Domain\Events\Support\AbstractDomainEvent;
use App\Domain\Properties\Models\Property;
use Illuminate\Database\Eloquent\Model;

class PropertyCreated extends AbstractDomainEvent
{
    public const NAME = 'property.created';

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
            'property_type' => $this->property->property_type->value,
            'city' => $this->property->city,
            'country_code' => $this->property->country_code,
            'currency' => $this->property->currency,
            'timezone' => $this->property->timezone,
        ];
    }
}
