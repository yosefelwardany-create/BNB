<?php

declare(strict_types=1);

namespace App\Domain\Availability\Events;

use App\Domain\Availability\Models\CalendarBlock;
use App\Domain\Events\Support\AbstractDomainEvent;
use Illuminate\Database\Eloquent\Model;

/**
 * Nights were taken off the market, or given back to it, by something other
 * than a booking.
 *
 * Kept separate from {@see CalendarDaysChanged} because a block has a
 * different shape: it hangs off a *property*, optionally narrowed to one unit
 * or one listing, whereas a calendar day belongs to exactly one listing. A
 * block on a property with four listings closes all four, and an event that
 * carried a single listing id could not say that.
 *
 * `end_date` is exclusive, matching reservations and the model itself.
 */
class CalendarBlockChanged extends AbstractDomainEvent
{
    public const NAME = 'calendar_block.changed';

    public const ACTION_CREATED = 'created';

    public const ACTION_REMOVED = 'removed';

    /**
     * The block survived but its dates moved — trimmed, or split in two.
     *
     * Needed because re-opening part of a longer block is neither a creation
     * nor a removal, and calling it either would misdescribe what happened.
     * The window such an event carries is the block's dates *before* the
     * change: those cover both the nights freed and the nights kept, and only
     * the pre-change window is guaranteed to include the freed ones.
     */
    public const ACTION_MODIFIED = 'modified';

    public function __construct(
        public readonly CalendarBlock $block,
        public readonly string $action,
    ) {
        parent::__construct();
    }

    public function subject(): ?Model
    {
        return $this->block;
    }

    public function payload(): array
    {
        return [
            'block_id' => $this->block->getKey(),
            'property_id' => $this->block->property_id,
            'unit_id' => $this->block->unit_id,
            'listing_id' => $this->block->listing_id,
            'kind' => $this->block->kind,
            'start_date' => $this->block->start_date->toDateString(),
            'end_date' => $this->block->end_date->toDateString(),
            'action' => $this->action,
        ];
    }
}
