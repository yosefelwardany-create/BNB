<?php

declare(strict_types=1);

namespace App\Domain\Locks\Services;

use App\Domain\Integrations\DataObjects\AccessCodeRequest;
use App\Domain\Integrations\Registries\LockProviderRegistry;
use App\Domain\Locks\Models\AccessCode;
use App\Domain\Locks\Models\SmartLock;
use App\Domain\Reservations\Models\Reservation;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Issuing and revoking door codes.
 *
 * The rule this service exists to enforce: a code is never reported as issued
 * until a lock has accepted it. Everything else follows from that.
 *
 * A code that exists only in our database — because the provider refused,
 * because the lock was offline, because no live provider is configured — is
 * recorded as `failed` or as simulated, never as active. Sending a guest a
 * number that no door recognises produces somebody standing outside at
 * midnight, and it is the single worst failure this module can have.
 *
 * Windows are computed in the property's own timezone and padded at both ends.
 * A guest arriving at 15:00 local needs a code that works at 14:45 because
 * their taxi was early, and a cleaner needs one that still works at 11:30
 * because the guest overslept.
 */
class AccessCodeManager
{
    public function __construct(
        private readonly LockProviderRegistry $providers,
        private readonly TenantContext $tenancy,
    ) {}

    /**
     * Issue codes for every lock a stay needs.
     *
     * A building entrance and a flat door are two locks and two codes, and a
     * guest given only one of them cannot get in.
     *
     * @return list<AccessCode>
     */
    public function issueForReservation(Reservation $reservation): array
    {
        $locks = SmartLock::query()
            ->active()
            ->where('property_id', $reservation->property_id)
            ->where(fn ($q) => $q->whereNull('unit_id')->orWhere('unit_id', $reservation->unit_id))
            ->get();

        $issued = [];

        foreach ($locks as $lock) {
            $code = $this->issue(
                $lock,
                $this->windowFor($reservation, $lock),
                AccessCode::GUEST,
                $reservation,
            );

            if ($code !== null) {
                $issued[] = $code;
            }
        }

        return $issued;
    }

    /**
     * Issue one code against one lock.
     *
     * @param  array{0: CarbonImmutable, 1: CarbonImmutable}  $window
     */
    public function issue(
        SmartLock $lock,
        array $window,
        string $purpose = AccessCode::GUEST,
        ?Reservation $reservation = null,
    ): ?AccessCode {
        [$from, $until] = $window;

        $provider = $this->providers->make($lock->provider);

        // Recorded before the attempt, so a provider that times out leaves a
        // row explaining what was tried rather than nothing at all.
        $record = AccessCode::query()->create([
            'organization_id' => $this->tenancy->organizationOrFail()->getKey(),
            'smart_lock_id' => $lock->getKey(),
            'reservation_id' => $reservation?->getKey(),
            'code' => $this->generateCode(),
            'purpose' => $purpose,
            'valid_from' => $from,
            'valid_until' => $until,
            'status' => AccessCode::PENDING,
            // Taken from the adapter, not from the lock record: the record
            // says what it was last time, the adapter says what it is now.
            'is_simulated' => ! $provider->isLive(),
        ]);

        try {
            $result = $provider->issueAccessCode($lock, new AccessCodeRequest(
                validFrom: $from->toDateTimeImmutable(),
                validUntil: $until->toDateTimeImmutable(),
                label: $this->labelFor($purpose, $reservation),
                code: $record->code,
                reservationReference: $reservation?->confirmation_code,
            ));
        } catch (\Throwable $exception) {
            return $this->fail($record, $exception->getMessage());
        }

        if (! $result->successful) {
            // Never active. A guest sent a code the lock refused is a guest
            // locked out, and the failure has to be visible to somebody who
            // can act on it before they arrive.
            return $this->fail($record, $result->errorMessage ?? 'The lock refused the code.');
        }

        $record->forceFill([
            'status' => AccessCode::ACTIVE,
            'external_code_id' => $result->externalCodeId,
            'issued_at' => now(),
            'last_error' => null,
        ]);

        // A provider that chose its own code wins: what the lock will accept
        // is what the lock decided, not what we suggested.
        if ($result->code !== null && $result->code !== $record->code) {
            $record->code = $result->code;
        }

        $record->save();

        return $record->fresh();
    }

    /**
     * Withdraw a code.
     *
     * The local record is updated whether or not the provider call succeeds,
     * and the failure is kept. A code we believe revoked but which the lock
     * still accepts is the dangerous case, so it has to be visible rather than
     * swallowed.
     */
    public function revoke(AccessCode $code, ?string $reason = null): AccessCode
    {
        if ($code->status === AccessCode::REVOKED) {
            return $code;
        }

        $lock = $code->lock;
        $error = null;

        if ($lock !== null && $code->external_code_id !== null) {
            try {
                $result = $this->providers->make($lock->provider)
                    ->revokeAccessCode($lock, $code->external_code_id);

                if (! $result->successful) {
                    $error = $result->errorMessage ?? 'The lock did not confirm the revocation.';
                }
            } catch (\Throwable $exception) {
                $error = $exception->getMessage();
            }
        }

        $code->forceFill([
            'status' => AccessCode::REVOKED,
            'revoked_at' => now(),
            'revoked_reason' => $reason,
            // Kept, not cleared: a code we think is revoked and the lock still
            // accepts is exactly what somebody needs to know about.
            'last_error' => $error,
        ])->save();

        if ($error !== null) {
            Log::warning('An access code was revoked locally but the lock did not confirm it.', [
                'access_code_id' => $code->getKey(),
                'error' => $error,
            ]);
        }

        return $code->fresh();
    }

    /**
     * Withdraw every code for a booking.
     *
     * What a cancellation calls. Every code goes, including the cleaner's:
     * the clean is not happening either.
     *
     * @return int how many were revoked
     */
    public function revokeForReservation(Reservation $reservation, ?string $reason = null): int
    {
        $codes = AccessCode::query()
            ->where('reservation_id', $reservation->getKey())
            ->whereIn('status', [AccessCode::PENDING, AccessCode::ACTIVE])
            ->get();

        foreach ($codes as $code) {
            $this->revoke($code, $reason ?? 'The booking was cancelled.');
        }

        return $codes->count();
    }

    /**
     * Mark codes whose window has passed.
     *
     * Not cosmetic: a lock has a finite number of code slots, and an expired
     * code still counted as active is why the next guest's code cannot be
     * issued.
     *
     * @return int how many were retired
     */
    public function retireExpired(): int
    {
        return AccessCode::query()->stale()->update([
            'status' => AccessCode::EXPIRED,
            'updated_at' => now(),
        ]);
    }

    /**
     * The window a code should cover, in the property's own time.
     *
     * Padded at both ends. A guest arriving at 15:00 local needs a code that
     * works at 14:45 because their taxi was early, and a departing guest needs
     * one that still works while they carry their bags down.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function windowFor(Reservation $reservation, SmartLock $lock): array
    {
        $timezone = $reservation->property?->timezone ?? 'UTC';

        $checkInTime = $reservation->property?->check_in_time ?? '15:00';
        $checkOutTime = $reservation->property?->check_out_time ?? '11:00';

        $from = CarbonImmutable::parse(
            $reservation->check_in_date->toDateString().' '.$checkInTime,
            $timezone,
        )->subMinutes((int) config('pms.locks.early_access_minutes', 60));

        $until = CarbonImmutable::parse(
            $reservation->check_out_date->toDateString().' '.$checkOutTime,
            $timezone,
        )->addMinutes((int) config('pms.locks.late_access_minutes', 60));

        return [$from, $until];
    }

    private function fail(AccessCode $record, string $error): AccessCode
    {
        $record->forceFill([
            'status' => AccessCode::FAILED,
            'last_error' => mb_substr($error, 0, 250),
        ])->save();

        Log::error('An access code could not be issued.', [
            'access_code_id' => $record->getKey(),
            'smart_lock_id' => $record->smart_lock_id,
            'error' => $error,
        ]);

        return $record->fresh();
    }

    private function labelFor(string $purpose, ?Reservation $reservation): string
    {
        return $reservation !== null
            ? sprintf('%s — %s', ucfirst($purpose), $reservation->confirmation_code)
            : ucfirst($purpose);
    }

    /**
     * A six-digit code.
     *
     * `random_int` rather than `rand`: these are keys, and a predictable key
     * is not a key. Six digits is what keypad locks accept, so the length is
     * the hardware's constraint rather than a choice — which is why the
     * validity window is short.
     */
    private function generateCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }
}
