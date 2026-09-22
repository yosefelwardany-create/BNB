<?php

declare(strict_types=1);

namespace App\Domain\Owners\Exceptions;

use RuntimeException;

/**
 * Raised when a property's ownership shares would not add up.
 *
 * Carries the offending dates and totals rather than a bare message, because
 * the useful answer to "why was this rejected?" is *which day* is over-
 * allocated and by how much.
 */
class OwnershipConflictException extends RuntimeException
{
    /**
     * @param  list<array{date: string, total: float}>  $conflicts
     */
    public function __construct(public readonly array $conflicts)
    {
        parent::__construct($this->describe());
    }

    private function describe(): string
    {
        $lines = array_map(
            static fn (array $conflict): string => sprintf(
                '%s is allocated %s%%',
                $conflict['date'],
                rtrim(rtrim(number_format($conflict['total'], 4, '.', ''), '0'), '.'),
            ),
            array_slice($this->conflicts, 0, 5),
        );

        $suffix = count($this->conflicts) > 5
            ? sprintf(' (and %d more dates)', count($this->conflicts) - 5)
            : '';

        return sprintf(
            'Ownership shares cannot exceed 100%% on any date: %s%s.',
            implode('; ', $lines),
            $suffix,
        );
    }

    /**
     * @return list<array{date: string, total: float}>
     */
    public function conflicts(): array
    {
        return $this->conflicts;
    }
}
