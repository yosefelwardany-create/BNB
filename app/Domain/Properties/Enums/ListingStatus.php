<?php

declare(strict_types=1);

namespace App\Domain\Properties\Enums;

/**
 * A listing's publication state.
 *
 * `Paused` differs from `Archived`: a paused listing keeps its channel
 * mappings and can be brought back in one action, whereas archiving is the
 * end of that offer.
 */
enum ListingStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Paused = 'paused';
    case Archived = 'archived';

    public function isBookable(): bool
    {
        return $this === self::Published;
    }

    /** Whether the listing should be kept in step with its channels. */
    public function isSynchronised(): bool
    {
        return in_array($this, [self::Published, self::Paused], true);
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
