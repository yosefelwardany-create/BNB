<?php

declare(strict_types=1);

namespace App\Domain\Platform\Services;

use App\Domain\Platform\Models\DocumentSequence;
use Illuminate\Support\Facades\DB;

/**
 * Allocates gap-free, per-organization document numbers.
 *
 * `SELECT ... FOR UPDATE` on the counter row serialises concurrent callers, so
 * two simultaneous bookings cannot be handed the same confirmation code. The
 * cost is a short row lock, which is the right trade for identifiers that
 * appear on guest-facing documents and in accounting records.
 */
class SequenceGenerator
{
    public const JOURNAL = 'journal';

    public const INVOICE = 'invoice';

    public const STATEMENT = 'statement';

    public const RESERVATION = 'reservation';

    public const PAYOUT = 'payout';

    public const EXPENSE = 'expense';

    /**
     * Take the next value for a sequence and render it with its prefix.
     *
     * Must be called inside a transaction so the lock is held until the
     * document that uses the number is itself committed.
     */
    public function next(string $organizationId, string $kind, ?string $defaultPrefix = null, int $padding = 6): string
    {
        return DB::transaction(function () use ($organizationId, $kind, $defaultPrefix, $padding): string {
            $sequence = DocumentSequence::query()
                ->withoutGlobalScope('organization')
                ->where('organization_id', $organizationId)
                ->where('kind', $kind)
                ->lockForUpdate()
                ->first();

            if ($sequence === null) {
                $sequence = DocumentSequence::query()->create([
                    'organization_id' => $organizationId,
                    'kind' => $kind,
                    'prefix' => $defaultPrefix,
                    'next_value' => 1,
                    'padding' => $padding,
                ]);
            }

            $value = (int) $sequence->next_value;

            $sequence->forceFill(['next_value' => $value + 1])->save();

            return $this->format($sequence->prefix ?? $defaultPrefix, $value, (int) $sequence->padding);
        });
    }

    /**
     * Allocate several consecutive numbers in one lock — used by batch
     * statement generation.
     *
     * @return list<string>
     */
    public function nextBatch(string $organizationId, string $kind, int $count, ?string $defaultPrefix = null): array
    {
        if ($count < 1) {
            return [];
        }

        return DB::transaction(function () use ($organizationId, $kind, $count, $defaultPrefix): array {
            $sequence = DocumentSequence::query()
                ->withoutGlobalScope('organization')
                ->where('organization_id', $organizationId)
                ->where('kind', $kind)
                ->lockForUpdate()
                ->first();

            if ($sequence === null) {
                $sequence = DocumentSequence::query()->create([
                    'organization_id' => $organizationId,
                    'kind' => $kind,
                    'prefix' => $defaultPrefix,
                    'next_value' => 1,
                ]);
            }

            $start = (int) $sequence->next_value;
            $sequence->forceFill(['next_value' => $start + $count])->save();

            $numbers = [];
            for ($i = 0; $i < $count; $i++) {
                $numbers[] = $this->format($sequence->prefix ?? $defaultPrefix, $start + $i, (int) $sequence->padding);
            }

            return $numbers;
        });
    }

    private function format(?string $prefix, int $value, int $padding): string
    {
        $number = str_pad((string) $value, max(1, $padding), '0', STR_PAD_LEFT);

        return $prefix === null || $prefix === '' ? $number : $prefix.'-'.$number;
    }
}
