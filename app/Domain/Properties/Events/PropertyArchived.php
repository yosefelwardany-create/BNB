<?php

declare(strict_types=1);

namespace App\Domain\Properties\Events;

use App\Domain\Events\Support\AbstractDomainEvent;
use App\Domain\Properties\Models\Property;
use Illuminate\Database\Eloquent\Model;

/**
 * The property has been retired. Consumers unpublish its channel listings and
 * stop scheduling work against it; nothing deletes its history.
 */
class PropertyArchived extends AbstractDomainEvent
{
    public const NAME = 'property.archived';

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
        ];
    }
}
