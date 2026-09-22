<?php

declare(strict_types=1);

namespace App\Domain\Availability\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Raised when a booking is attempted on nights that cannot be sold.
 *
 * Carries the reasons and the offending dates so the caller can tell a guest
 * something useful instead of "unavailable".
 *
 * Surfaces as HTTP 409: the request was well formed, but the world changed or
 * the dates were never sellable.
 */
class DatesUnavailableException extends HttpException
{
    /**
     * @param  list<string>  $reasons
     * @param  list<string>  $blockedDates
     */
    public function __construct(
        public readonly array $reasons,
        public readonly array $blockedDates = [],
    ) {
        parent::__construct(409, $reasons === []
            ? 'Those dates are not available.'
            : implode(' ', $reasons));
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return [
            'reasons' => $this->reasons,
            'blocked_dates' => $this->blockedDates,
        ];
    }
}
