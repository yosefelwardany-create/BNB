<?php

declare(strict_types=1);

namespace App\Http\Requests\Reservations;

use App\Domain\Reservations\Enums\ReservationStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'listing_id' => ['required', 'string', 'exists:listings,id'],
            'unit_id' => ['sometimes', 'nullable', 'string', 'exists:units,id'],

            'check_in' => ['required', 'date'],
            'check_out' => ['required', 'date', 'after:check_in'],

            'adults' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'children' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'infants' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'pets' => ['sometimes', 'integer', 'min:0', 'max:20'],

            'status' => ['sometimes', Rule::enum(ReservationStatus::class)],
            'source' => ['sometimes', 'string', 'max:48'],

            /*
             * The channel's own reference for this booking.
             *
             * The column has always been there, written by the channel importer
             * and returned by the resource, and the only thing missing was a rule
             * letting a person send one — so a booking copied off Airbnb by hand
             * could not carry the code Airbnb knows it by. That code is how the
             * two records are reconciled when a payout is queried eleven months
             * later, and the alternative was writing it into a notes field.
             */
            'external_confirmation_code' => ['sometimes', 'nullable', 'string', 'max:64'],
            'external_reservation_id' => ['sometimes', 'nullable', 'string', 'max:128'],

            // Either an existing guest, or the details to create one.
            'guest_id' => ['sometimes', 'nullable', 'string', 'exists:guests,id'],
            'guest' => ['required_without:guest_id', 'array'],
            'guest.first_name' => ['required_with:guest', 'string', 'max:80'],
            'guest.last_name' => ['sometimes', 'nullable', 'string', 'max:80'],
            'guest.email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'guest.phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'guest.country_code' => ['sometimes', 'nullable', 'string', 'size:2'],
            'guest.language' => ['sometimes', 'nullable', 'string', 'max:12'],

            'promotion_code' => ['sometimes', 'nullable', 'string', 'max:64'],
            'guest_notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'internal_notes' => ['sometimes', 'nullable', 'string', 'max:5000'],

            // Honoured only for users who also hold
            // reservations.override_availability.
            'override_restrictions' => ['sometimes', 'boolean'],

            /*
             * A stay already under way or finished, being written down.
             *
             * Deliberately not behind a permission. Anyone who may take a
             * booking may record one that already happened — an operator moving
             * from another system has months of history and guests in the
             * building, and a platform that cannot hold those cannot be used at
             * all. It waives only the lead-time rules; inventory is still
             * checked, so it is not a way to double-sell a night.
             */
            'records_existing_stay' => ['sometimes', 'boolean'],

            // When the booking was actually taken. Defaults to now, which is
            // wrong for anything being entered after the fact.
            'booked_at' => ['sometimes', 'nullable', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'check_out.after' => 'The departure date must be after the arrival date.',
            'guest.required_without' => 'Provide either an existing guest_id or the guest\'s details.',
        ];
    }

    protected function prepareForValidation(): void
    {
        // A stay longer than the configured ceiling is almost always a
        // mistyped year rather than a genuine booking.
        if ($this->filled(['check_in', 'check_out'])) {
            $nights = CarbonImmutable::parse($this->input('check_in'))
                ->diffInDays(CarbonImmutable::parse($this->input('check_out')));

            $max = (int) config('pms.reservations.max_nights', 365);

            if ($nights > $max) {
                abort(422, sprintf(
                    'That stay is %d nights long. The maximum is %d — please check the dates.',
                    (int) $nights,
                    $max,
                ));
            }
        }
    }
}
