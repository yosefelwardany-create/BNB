<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Providers\Locks;

use App\Domain\Integrations\Contracts\LockProviderInterface;
use App\Domain\Integrations\DataObjects\AccessCodeRequest;
use App\Domain\Integrations\DataObjects\AccessCodeResult;
use App\Domain\Integrations\DataObjects\LockStatus;
use App\Domain\Locks\Models\SmartLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A working local smart-lock vendor.
 *
 * It keeps real state: locks have battery levels that drain as codes are
 * issued, codes occupy slots, and a lock refuses a code whose window has
 * already passed. That is enough to exercise the scheduling, revocation and
 * low-battery workflows without hardware.
 *
 * `isLive()` is false, and the UI labels such a connection as simulated.
 */
class MockLockProvider implements LockProviderInterface
{
    private const LOCKS = 'simulated_locks';

    private const CODES = 'simulated_access_codes';

    /** Real keypad locks have a finite number of code slots. */
    private const MAX_ACTIVE_CODES = 20;

    public function key(): string
    {
        return 'mock';
    }

    public function displayName(): string
    {
        return 'Simulated smart lock (development)';
    }

    public function isLive(): bool
    {
        return false;
    }

    public function capabilities(): array
    {
        return ['codes', 'remote_unlock', 'battery', 'schedule'];
    }

    public function listLocks(string $connectionId): array
    {
        $rows = DB::table(self::LOCKS)->where('connection_id', $connectionId)->get();

        return $rows->map(fn (object $row): LockStatus => $this->toStatus($row))->all();
    }

    public function status(SmartLock $lock): LockStatus
    {
        $row = $this->findLock($lock);

        if ($row === null) {
            return new LockStatus(
                externalLockId: (string) $lock->external_lock_id,
                name: (string) $lock->name,
                online: false,
            );
        }

        return $this->toStatus($row);
    }

    public function issueAccessCode(SmartLock $lock, AccessCodeRequest $request): AccessCodeResult
    {
        $row = $this->findLock($lock);

        if ($row === null) {
            return AccessCodeResult::failure('lock_not_found', 'This lock is not known to the provider.');
        }

        if (! $row->online) {
            // A real lock that is offline cannot be programmed; the caller is
            // expected to retry once it reports in.
            return AccessCodeResult::failure('lock_offline', 'The lock is offline.', retryable: true);
        }

        if ($request->validUntil <= $request->validFrom) {
            return AccessCodeResult::failure('invalid_window', 'The access window ends before it begins.');
        }

        if ($request->validUntil < now()->toDateTimeImmutable()) {
            return AccessCodeResult::failure('window_in_past', 'The access window has already passed.');
        }

        $activeCodes = DB::table(self::CODES)
            ->where('external_lock_id', $row->external_lock_id)
            ->whereNull('revoked_at')
            ->where('valid_until', '>', now())
            ->count();

        if ($activeCodes >= self::MAX_ACTIVE_CODES) {
            return AccessCodeResult::failure(
                'no_free_slots',
                'The lock has no free code slots. Revoke an expired code first.',
            );
        }

        $code = $request->code ?? $this->generateCode();
        $externalCodeId = 'code_'.Str::lower((string) Str::ulid());

        DB::table(self::CODES)->insert([
            'id' => (string) Str::ulid(),
            'external_lock_id' => $row->external_lock_id,
            'external_code_id' => $externalCodeId,
            'code' => $code,
            'label' => $request->label,
            'valid_from' => $request->validFrom,
            'valid_until' => $request->validUntil,
            'reservation_reference' => $request->reservationReference,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Programming a lock costs battery.
        DB::table(self::LOCKS)
            ->where('id', $row->id)
            ->update([
                'battery_percent' => max(0, (int) $row->battery_percent - 1),
                'last_seen_at' => now(),
                'updated_at' => now(),
            ]);

        return AccessCodeResult::issued($externalCodeId, $code);
    }

    public function revokeAccessCode(SmartLock $lock, string $externalCodeId): AccessCodeResult
    {
        $updated = DB::table(self::CODES)
            ->where('external_code_id', $externalCodeId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'updated_at' => now()]);

        if ($updated === 0) {
            // Already gone: revocation is idempotent by design, because the
            // platform retries it when a reservation is cancelled.
            return AccessCodeResult::revoked($externalCodeId);
        }

        return AccessCodeResult::revoked($externalCodeId);
    }

    public function unlock(SmartLock $lock): bool
    {
        return $this->setLocked($lock, false);
    }

    public function lock(SmartLock $lock): bool
    {
        return $this->setLocked($lock, true);
    }

    private function setLocked(SmartLock $lock, bool $locked): bool
    {
        $row = $this->findLock($lock);

        if ($row === null || ! $row->online) {
            return false;
        }

        DB::table(self::LOCKS)->where('id', $row->id)->update([
            'locked' => $locked,
            'battery_percent' => max(0, (int) $row->battery_percent - 1),
            'last_seen_at' => now(),
            'updated_at' => now(),
        ]);

        return true;
    }

    private function findLock(SmartLock $lock): ?object
    {
        return DB::table(self::LOCKS)
            ->where('external_lock_id', $lock->external_lock_id)
            ->first();
    }

    private function toStatus(object $row): LockStatus
    {
        return new LockStatus(
            externalLockId: (string) $row->external_lock_id,
            name: (string) $row->name,
            online: (bool) $row->online,
            locked: (bool) $row->locked,
            batteryPercent: (int) $row->battery_percent,
            model: $row->model,
            lastSeenAt: $row->last_seen_at !== null
                ? new \DateTimeImmutable((string) $row->last_seen_at)
                : null,
            raw: ['simulated' => true],
        );
    }

    private function generateCode(): string
    {
        return str_pad((string) random_int(1000, 999999), 6, '0', STR_PAD_LEFT);
    }
}
