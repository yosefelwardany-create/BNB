<?php

declare(strict_types=1);

namespace App\Domain\Reports\DataObjects;

/**
 * What happened when one report reached one destination.
 *
 * `simulated` is here for the same reason it is on every other delivery in
 * this product: a scheduled report that failed silently looks exactly like a
 * report with nothing to say, and somebody will spend a month believing
 * occupancy was flat.
 */
final class ReportDeliveryOutcome
{
    /**
     * @param  array<string, mixed>  $data
     */
    private function __construct(
        public readonly string $destination,
        /** Who or what it went to, for the run log. Never the report itself. */
        public readonly string $target,
        public readonly bool $successful,
        public readonly bool $simulated,
        public readonly ?string $detail = null,
        public readonly array $data = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function delivered(string $destination, string $target, array $data = []): self
    {
        return new self($destination, $target, true, false, null, $data);
    }

    /**
     * Accepted, but nothing left the building — a local mail transport, a
     * file written to a disk nobody has mounted anywhere.
     *
     * @param  array<string, mixed>  $data
     */
    public static function recordedLocally(
        string $destination,
        string $target,
        string $detail,
        array $data = [],
    ): self {
        return new self($destination, $target, true, true, $detail, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function failed(
        string $destination,
        string $target,
        string $detail,
        array $data = [],
    ): self {
        return new self($destination, $target, false, false, $detail, $data);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'destination' => $this->destination,
            'target' => $this->target,
            'successful' => $this->successful,
            'simulated' => $this->simulated,
            'detail' => $this->detail,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
