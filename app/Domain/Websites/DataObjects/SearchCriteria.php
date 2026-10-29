<?php

declare(strict_types=1);

namespace App\Domain\Websites\DataObjects;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Throwable;

/**
 * What a guest asked a site's search for, read leniently.
 *
 * A search form is typed into by anybody: a bad date or a stray letter is a
 * sentence on the page, never an error page. Dates that cannot both be used
 * are dropped together, and {@see problem()} says why.
 */
final class SearchCriteria
{
    /** The longest stay a site will look for. */
    public const MAX_NIGHTS = 365;

    /**
     * @param  list<string>  $amenities  amenity keys, all of which must be present
     */
    public function __construct(
        public readonly ?CarbonImmutable $checkIn = null,
        public readonly ?CarbonImmutable $checkOut = null,
        public readonly int $guests = 1,
        public readonly ?int $minPrice = null,
        public readonly ?int $maxPrice = null,
        public readonly ?int $bedrooms = null,
        public readonly ?int $bathrooms = null,
        public readonly array $amenities = [],
        private readonly ?string $problem = null,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $checkIn = self::date($request->query('checkin'));
        $checkOut = self::date($request->query('checkout'));
        $problem = null;

        if (($checkIn === null) !== ($checkOut === null)) {
            $problem = 'Choose both a check-in and a check-out date.';
        } elseif ($checkIn !== null && $checkOut !== null && $checkOut->lessThanOrEqualTo($checkIn)) {
            $problem = 'Check-out must be after check-in.';
        } elseif ($checkIn !== null && $checkIn->lessThan(CarbonImmutable::today())) {
            $problem = 'Those dates are in the past.';
        } elseif ($checkIn !== null && $checkOut !== null && $checkIn->diffInDays($checkOut) > self::MAX_NIGHTS) {
            // Every night is priced, for every listing on the site: a stay ten
            // years long is refused here, not computed.
            $problem = sprintf('Stays can be up to %d nights.', self::MAX_NIGHTS);
        }

        if ($problem !== null) {
            $checkIn = $checkOut = null;
        }

        $amenities = $request->query('amenities', []);

        return new self(
            checkIn: $checkIn,
            checkOut: $checkOut,
            guests: max(1, min(50, self::int($request->query('guests')) ?? 1)),
            minPrice: self::int($request->query('min_price')),
            maxPrice: self::int($request->query('max_price')),
            bedrooms: self::int($request->query('bedrooms')),
            bathrooms: self::int($request->query('bathrooms')),
            amenities: is_array($amenities)
                ? array_values(array_filter($amenities, fn (mixed $key): bool => is_string($key) && $key !== ''))
                : [],
            problem: $problem,
        );
    }

    public function hasDates(): bool
    {
        return $this->checkIn !== null && $this->checkOut !== null;
    }

    public function nights(): int
    {
        return $this->hasDates() ? (int) $this->checkIn->diffInDays($this->checkOut) : 0;
    }

    /**
     * Why the dates typed were not used, in the guest's words.
     */
    public function problem(): ?string
    {
        return $this->problem;
    }

    /**
     * The query string that reproduces this search, for links on the page.
     *
     * @return array<string, mixed>
     */
    public function query(): array
    {
        return array_filter([
            'checkin' => $this->checkIn?->toDateString(),
            'checkout' => $this->checkOut?->toDateString(),
            'guests' => $this->guests > 1 ? $this->guests : null,
        ], fn (mixed $value): bool => $value !== null);
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $value) ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function int(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value >= 0 ? (int) $value : null;
    }
}
