<?php

declare(strict_types=1);

namespace App\Domain\Platform\Services;

use App\Domain\Platform\Exceptions\ConcurrentRequestException;
use App\Domain\Platform\Models\IdempotencyKey;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Runs an operation at most once for a given key.
 *
 * The contract is deliberately strict because the callers are the places where
 * a duplicate is most expensive: taking a payment twice, creating the same
 * reservation twice from a redelivered channel webhook, or sending the same
 * guest message twice.
 *
 * Flow:
 *   1. Insert a row in `in_progress` state. The unique index on (scope, key)
 *      makes this the lock — only one caller can win.
 *   2. Run the operation, store its result, mark the row `completed`.
 *   3. A later caller with the same key gets the stored result back without
 *      re-running anything.
 *
 * A caller that arrives while the first is still running is told to retry
 * rather than being allowed to proceed in parallel.
 */
class IdempotencyGuard
{
    /**
     * Execute $operation at most once for ($scope, $key).
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $operation
     * @param  null|Closure(IdempotencyKey): TReturn  $onReplay  Called instead of
     *                                                           $operation when the key has already completed. Defaults to
     *                                                           returning the stored response array.
     * @return TReturn|array<string, mixed>|null
     */
    public function run(
        string $scope,
        string $key,
        Closure $operation,
        ?Closure $onReplay = null,
        ?string $organizationId = null,
        ?string $fingerprint = null,
        ?int $retentionDays = 30,
    ): mixed {
        $existing = $this->find($scope, $key);

        if ($existing !== null) {
            return $this->replay($existing, $onReplay);
        }

        try {
            $record = IdempotencyKey::query()->create([
                'organization_id' => $organizationId,
                'scope' => $scope,
                'key' => $key,
                'status' => IdempotencyKey::STATUS_IN_PROGRESS,
                'request_fingerprint' => $fingerprint,
                'locked_at' => now(),
                'expires_at' => $retentionDays === null ? null : now()->addDays($retentionDays),
            ]);
        } catch (QueryException $exception) {
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            // Another caller won the race.
            $existing = $this->find($scope, $key);

            if ($existing === null) {
                throw $exception;
            }

            return $this->replay($existing, $onReplay);
        }

        try {
            $result = $operation();
        } catch (Throwable $exception) {
            // Release the key so the caller can legitimately retry after
            // fixing the cause. Deleting rather than marking failed keeps the
            // semantics simple: a key only exists for work that succeeded or
            // is actively running.
            $record->forceFill([
                'status' => IdempotencyKey::STATUS_FAILED,
                'completed_at' => now(),
            ])->save();

            $record->delete();

            throw $exception;
        }

        $record->forceFill([
            'status' => IdempotencyKey::STATUS_COMPLETED,
            'completed_at' => now(),
            'subject_type' => $result instanceof Model ? $result->getMorphClass() : null,
            'subject_id' => $result instanceof Model ? $result->getKey() : null,
            'response' => $this->serialise($result),
        ])->save();

        return $result;
    }

    /**
     * Whether an operation has already completed for this key.
     */
    public function hasCompleted(string $scope, string $key): bool
    {
        return IdempotencyKey::query()
            ->where('scope', $scope)
            ->where('key', $key)
            ->where('status', IdempotencyKey::STATUS_COMPLETED)
            ->exists();
    }

    /**
     * The record previously produced for a key, if it is still resolvable.
     */
    public function completedSubject(string $scope, string $key): ?Model
    {
        $record = IdempotencyKey::query()
            ->where('scope', $scope)
            ->where('key', $key)
            ->where('status', IdempotencyKey::STATUS_COMPLETED)
            ->first();

        return $record?->subject;
    }

    /**
     * Delete expired keys. Called by the scheduler.
     */
    public function prune(): int
    {
        return IdempotencyKey::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->delete();
    }

    private function find(string $scope, string $key): ?IdempotencyKey
    {
        return IdempotencyKey::query()
            ->where('scope', $scope)
            ->where('key', $key)
            ->first();
    }

    private function replay(IdempotencyKey $record, ?Closure $onReplay): mixed
    {
        if ($record->isInProgress()) {
            // Stale locks (a worker died mid-flight) are reclaimed after a
            // grace period so a key cannot wedge forever.
            if ($record->locked_at !== null && $record->locked_at->lt(now()->subMinutes(15))) {
                $record->delete();

                throw new ConcurrentRequestException(
                    'A previous attempt for this idempotency key did not finish. The lock has been released; please retry.'
                );
            }

            throw new ConcurrentRequestException(
                'A request with this idempotency key is currently in progress.'
            );
        }

        if ($onReplay !== null) {
            return $onReplay($record);
        }

        $subject = $record->subject;

        return $subject ?? $record->response;
    }

    private function serialise(mixed $result): ?array
    {
        if ($result === null) {
            return null;
        }

        if ($result instanceof Model) {
            return ['type' => $result->getMorphClass(), 'id' => $result->getKey()];
        }

        if (is_array($result)) {
            return $result;
        }

        if (is_scalar($result)) {
            return ['value' => $result];
        }

        return null;
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return in_array($exception->getCode(), ['23505', '23000'], true);
    }

    /**
     * Convenience helper: run the operation inside a database transaction as
     * well as the idempotency guard.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $operation
     * @return TReturn|array<string, mixed>|null
     */
    public function runInTransaction(
        string $scope,
        string $key,
        Closure $operation,
        ?Closure $onReplay = null,
        ?string $organizationId = null,
    ): mixed {
        return $this->run(
            scope: $scope,
            key: $key,
            operation: fn () => DB::transaction($operation),
            onReplay: $onReplay,
            organizationId: $organizationId,
        );
    }
}
