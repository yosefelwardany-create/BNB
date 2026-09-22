<?php

declare(strict_types=1);

namespace App\Domain\Guests\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Guests\Models\Guest;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Models\Reservation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Finding, creating, de-duplicating and merging guest profiles.
 *
 * Duplicates are the normal state of affairs in this industry: the same person
 * books through Airbnb in March and directly in September, and the two arrive
 * with different email addresses and differently formatted phone numbers.
 * Getting this right is what makes repeat-guest recognition and lifetime value
 * mean anything.
 */
class GuestDirectory
{
    public function __construct(
        private readonly TenantContext $tenancy,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Find an existing profile for these details, or create one.
     *
     * Matching is deliberately conservative: email or phone must match
     * exactly (after normalisation). Name similarity alone is never enough to
     * merge two people — "John Smith" is not evidence.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function findOrCreate(array $attributes): Guest
    {
        $existing = $this->findMatch($attributes);

        if ($existing !== null) {
            return $this->enrich($existing, $attributes);
        }

        return Guest::query()->create([
            'organization_id' => $this->tenancy->id(),
            'first_name' => $attributes['first_name'] ?? 'Guest',
            'last_name' => $attributes['last_name'] ?? null,
            'email' => $attributes['email'] ?? null,
            'phone' => $attributes['phone'] ?? null,
            'country_code' => $attributes['country_code'] ?? null,
            'language' => $attributes['language'] ?? null,
            'timezone' => $attributes['timezone'] ?? null,
            'source' => $attributes['source'] ?? null,
            'notes' => $attributes['notes'] ?? null,
            'created_by_id' => auth()->id(),
        ]);
    }

    /**
     * An existing profile matching these details, if one exists.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function findMatch(array $attributes): ?Guest
    {
        $email = Guest::normaliseEmail($attributes['email'] ?? null);
        $phone = Guest::normalisePhone($attributes['phone'] ?? null);

        if ($email === null && $phone === null) {
            return null;
        }

        return Guest::query()
            ->active()
            ->where(function ($query) use ($email, $phone): void {
                if ($email !== null) {
                    $query->orWhere('email_normalised', $email);
                }

                if ($phone !== null) {
                    $query->orWhere('phone_normalised', $phone);
                }
            })
            // Prefer the profile with the most history: it is the one other
            // records are most likely to reference.
            ->orderByDesc('reservations_count')
            ->first();
    }

    /**
     * Fill in details a profile is missing, without overwriting what it has.
     *
     * A channel that supplies only a masked email must never blank out a real
     * one collected directly.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function enrich(Guest $guest, array $attributes): Guest
    {
        $fillable = [
            'first_name', 'last_name', 'email', 'phone', 'country_code',
            'language', 'timezone', 'address_line_1', 'city', 'postal_code',
            'company', 'date_of_birth',
        ];

        $changed = false;

        foreach ($fillable as $field) {
            $incoming = $attributes[$field] ?? null;

            if ($incoming === null || $incoming === '') {
                continue;
            }

            if (blank($guest->getAttribute($field))) {
                $guest->setAttribute($field, $incoming);
                $changed = true;
            }
        }

        if ($changed) {
            $guest->save();
        }

        return $guest;
    }

    /**
     * Profiles that look like duplicates of this one.
     *
     * Only exact normalised email or phone matches are reported, because a
     * merge is destructive from the user's point of view and a false positive
     * costs more than a missed duplicate.
     *
     * @return Collection<int, Guest>
     */
    public function findDuplicates(Guest $guest): Collection
    {
        if ($guest->email_normalised === null && $guest->phone_normalised === null) {
            return collect();
        }

        return Guest::query()
            ->active()
            ->whereKeyNot($guest->getKey())
            ->where(function ($query) use ($guest): void {
                if ($guest->email_normalised !== null) {
                    $query->orWhere('email_normalised', $guest->email_normalised);
                }

                if ($guest->phone_normalised !== null) {
                    $query->orWhere('phone_normalised', $guest->phone_normalised);
                }
            })
            ->get();
    }

    /**
     * Every duplicate cluster in the organization, for a review screen.
     *
     * @return list<array{key: string, guest_ids: list<string>}>
     */
    public function duplicateClusters(int $limit = 100): array
    {
        $organizationId = $this->tenancy->id();

        $rows = DB::table('guests')
            ->selectRaw('email_normalised as key, array_agg(id) as ids, count(*) as total')
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->whereNull('merged_into_id')
            ->whereNotNull('email_normalised')
            ->groupBy('email_normalised')
            ->havingRaw('count(*) > 1')
            ->limit($limit)
            ->get();

        return $rows->map(fn (object $row): array => [
            'key' => (string) $row->key,
            'guest_ids' => $this->parsePostgresArray((string) $row->ids),
        ])->all();
    }

    /**
     * Merge one profile into another.
     *
     * The losing profile is kept and marked as merged rather than deleted, so
     * that any external reference to its id — a channel's guest identifier, a
     * printed confirmation, an old report — still resolves. Nothing about a
     * guest's history is ever discarded.
     */
    public function merge(Guest $winner, Guest $loser, ?string $reason = null): Guest
    {
        if ($winner->is($loser)) {
            return $winner;
        }

        return DB::transaction(function () use ($winner, $loser, $reason): Guest {
            // Move the history across.
            Reservation::query()
                ->where('guest_id', $loser->getKey())
                ->update(['guest_id' => $winner->getKey()]);

            DB::table('reservation_guests')
                ->where('guest_id', $loser->getKey())
                ->update(['guest_id' => $winner->getKey()]);

            // Take any detail the winner is missing.
            $this->enrich($winner, [
                'email' => $loser->email,
                'phone' => $loser->phone,
                'first_name' => $loser->first_name,
                'last_name' => $loser->last_name,
                'country_code' => $loser->country_code,
                'language' => $loser->language,
                'company' => $loser->company,
                'date_of_birth' => $loser->date_of_birth?->toDateString(),
            ]);

            // Consent transfers only when it was actually given; merging must
            // never manufacture a permission to market to someone.
            if ($loser->marketing_consent && ! $winner->marketing_consent) {
                $winner->forceFill([
                    'marketing_consent' => true,
                    'marketing_consent_at' => $loser->marketing_consent_at,
                    'marketing_consent_source' => $loser->marketing_consent_source,
                ]);
            }

            // Notes are concatenated rather than replaced.
            if (filled($loser->notes)) {
                $winner->notes = trim(($winner->notes ?? '')."\n\n[Merged profile] ".$loser->notes);
            }

            $winner->save();

            $winner->tagWith($loser->tagNames());

            $loser->forceFill(['merged_into_id' => $winner->getKey()])->save();

            $this->recomputeStatistics($winner);

            $this->audit->record(
                action: 'guest.merged',
                subject: $winner,
                oldValues: ['merged_guest_id' => $loser->getKey(), 'merged_guest_email' => $loser->email],
                newValues: ['surviving_guest_id' => $winner->getKey()],
                description: $reason ?? sprintf(
                    'Merged guest profile %s into %s',
                    $loser->display_name,
                    $winner->display_name,
                ),
            );

            return $winner->refresh();
        });
    }

    /**
     * Recompute a guest's lifetime figures from their reservations.
     *
     * Called whenever a booking is created, changed or cancelled. The figures
     * are a cache over the reservations, which remain the source of truth.
     */
    public function recomputeStatistics(?Guest $guest): void
    {
        if ($guest === null) {
            return;
        }

        $stats = Reservation::query()
            ->where('guest_id', $guest->getKey())
            ->whereIn('status', ReservationStatus::revenueValues())
            ->selectRaw('count(*) as reservations, coalesce(sum(nights), 0) as nights, coalesce(sum(grand_total), 0) as value, min(check_in_date) as first_stay, max(check_in_date) as last_stay')
            ->first();

        $guest->forceFill([
            'reservations_count' => (int) ($stats->reservations ?? 0),
            'nights_count' => (int) ($stats->nights ?? 0),
            'lifetime_value' => (int) ($stats->value ?? 0),
            'lifetime_value_currency' => $guest->lifetime_value_currency
                ?? $guest->organization?->base_currency,
            'first_stay_date' => $stats->first_stay ?? null,
            'last_stay_date' => $stats->last_stay ?? null,
        ])->saveQuietly();
    }

    /**
     * Postgres returns aggregated arrays as `{a,b,c}`.
     *
     * @return list<string>
     */
    private function parsePostgresArray(string $value): array
    {
        $trimmed = trim($value, '{}');

        if ($trimmed === '') {
            return [];
        }

        return array_map(
            fn (string $item): string => trim($item, '"'),
            explode(',', $trimmed),
        );
    }
}
