<?php

declare(strict_types=1);

namespace App\Domain\Accounting\DataObjects;

use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * An entry being built, before it is posted.
 *
 * Callers describe what happened in the language of accounts and amounts —
 * `debit(CASH, $amount)`, `credit(ACCOMMODATION_REVENUE, $amount)` — and the
 * poster turns that into rows. Building the entry as a value object rather
 * than writing lines directly means an unbalanced draft is caught before
 * anything reaches the database, and the same draft can be inspected in a test
 * without a transaction.
 *
 * Attribution (property, owner, reservation) is set once on the draft and
 * inherited by every line, because a line attributed to a different property
 * from its entry is almost always a mistake. A line can still override it
 * where that is genuinely the intent — a single payout entry spanning several
 * properties, for instance.
 */
final class JournalDraft
{
    /** @var list<array{account: string, debit: Money|null, credit: Money|null, memo: string|null, attribution: array<string, string|null>}> */
    private array $lines = [];

    private function __construct(
        public readonly string $description,
        public readonly string $source,
        public readonly CarbonImmutable $entryDate,
        public readonly ?Model $subject = null,
        public readonly ?string $propertyId = null,
        public readonly ?string $ownerId = null,
        public readonly ?string $reservationId = null,
        public readonly array $metadata = [],
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function for(
        string $description,
        string $source,
        ?CarbonImmutable $entryDate = null,
        ?Model $subject = null,
        ?string $propertyId = null,
        ?string $ownerId = null,
        ?string $reservationId = null,
        array $metadata = [],
    ): self {
        return new self(
            description: $description,
            source: $source,
            entryDate: $entryDate ?? CarbonImmutable::today(),
            subject: $subject,
            propertyId: $propertyId,
            ownerId: $ownerId,
            reservationId: $reservationId,
            metadata: $metadata,
        );
    }

    /**
     * @param  array<string, string|null>  $attribution  Overrides for this line only.
     */
    public function debit(string $accountKey, Money $amount, ?string $memo = null, array $attribution = []): self
    {
        return $this->line($accountKey, $amount, null, $memo, $attribution);
    }

    /**
     * @param  array<string, string|null>  $attribution
     */
    public function credit(string $accountKey, Money $amount, ?string $memo = null, array $attribution = []): self
    {
        return $this->line($accountKey, null, $amount, $memo, $attribution);
    }

    /**
     * @param  array<string, string|null>  $attribution
     */
    private function line(
        string $accountKey,
        ?Money $debit,
        ?Money $credit,
        ?string $memo,
        array $attribution,
    ): self {
        $amount = $debit ?? $credit;

        // A zero line carries no information and would violate the database's
        // single-sided check, so it is dropped rather than written.
        if ($amount === null || $amount->isZero()) {
            return $this;
        }

        // A negative amount means the caller has the sides the wrong way
        // round. Flipping it silently would post the opposite of what they
        // asked for, so it is flipped *and* the memo says so.
        if ($amount->isNegative()) {
            return $this->line(
                $accountKey,
                $credit === null ? null : $credit->absolute(),
                $debit === null ? null : $debit->absolute(),
                $memo,
                $attribution,
            );
        }

        $clone = clone $this;

        $clone->lines[] = [
            'account' => $accountKey,
            'debit' => $debit,
            'credit' => $credit,
            'memo' => $memo,
            'attribution' => $attribution,
        ];

        return $clone;
    }

    /**
     * @return list<array{account: string, debit: Money|null, credit: Money|null, memo: string|null, attribution: array<string, string|null>}>
     */
    public function lines(): array
    {
        return $this->lines;
    }

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }

    /**
     * The currency of the entry, taken from its first line.
     */
    public function currency(): ?string
    {
        $first = $this->lines[0] ?? null;

        if ($first === null) {
            return null;
        }

        return ($first['debit'] ?? $first['credit'])->currency;
    }

    /**
     * Total debits minus total credits, in minor units.
     *
     * Zero means the entry balances. Anything else is a bug in whatever built
     * it, and the poster refuses to write it.
     */
    public function imbalance(): int
    {
        $total = 0;

        foreach ($this->lines as $line) {
            $total += $line['debit']?->minorUnits ?? 0;
            $total -= $line['credit']?->minorUnits ?? 0;
        }

        return $total;
    }

    public function balances(): bool
    {
        return $this->imbalance() === 0;
    }

    /**
     * A readable rendering, used in the exception a failed post throws so the
     * problem is visible without reconstructing the draft by hand.
     */
    public function describe(): string
    {
        $rows = array_map(
            static fn (array $line): string => sprintf(
                '  %-28s %14s %14s',
                $line['account'],
                $line['debit']?->toDecimal() ?? '',
                $line['credit']?->toDecimal() ?? '',
            ),
            $this->lines,
        );

        return sprintf("%s\n%s", $this->description, implode("\n", $rows));
    }
}
