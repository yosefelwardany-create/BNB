<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Services;

use App\Domain\Guests\Models\Guest;
use App\Domain\Messaging\Exceptions\TemplateRenderException;
use App\Domain\Properties\Models\Property;
use App\Domain\Reservations\Models\Reservation;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;

/**
 * Renders `{{ placeholder }}` templates.
 *
 * This is a substitution engine, not an interpreter. Templates are written by
 * customers and sent to guests, so the rules are strict:
 *
 *  - Only names from a fixed allow-list resolve. Anything else is either left
 *    visible as an unresolved placeholder or reported as an error, never
 *    guessed at and never reached through arbitrary property access.
 *  - No expressions, no function calls, no loops, no PHP. There is nothing to
 *    execute, so there is nothing to inject. The obvious shortcut here —
 *    running templates through Blade — would let a customer's template read
 *    the filesystem or run code on the server.
 *  - Values are inserted verbatim into plain-text bodies and HTML-escaped for
 *    HTML bodies, so a guest's name containing `<script>` cannot become
 *    markup in an email.
 *
 * A placeholder may carry one formatting modifier: `{{ check_in_date|long }}`.
 */
class TemplateRenderer
{
    /**
     * The complete placeholder vocabulary, with a one-line description used by
     * the template editor's picker.
     *
     * @return array<string, array<string, string>> group => [placeholder => description]
     */
    public static function vocabulary(): array
    {
        return [
            'Guest' => [
                'guest.first_name' => "The guest's first name",
                'guest.last_name' => "The guest's surname",
                'guest.full_name' => "The guest's full name",
                'guest.email' => "The guest's email address",
                'guest.phone' => "The guest's phone number",
                'guest.country' => "The guest's country",
            ],
            'Reservation' => [
                'reservation.confirmation_code' => 'The booking reference',
                'reservation.status' => 'The booking status',
                'reservation.source' => 'Where the booking came from',
                'reservation.nights' => 'Number of nights',
                'reservation.guests' => 'Number of guests',
                'reservation.adults' => 'Number of adults',
                'reservation.children' => 'Number of children',
                'reservation.total' => 'Total amount',
                'reservation.balance_due' => 'Amount still owed',
                'reservation.amount_paid' => 'Amount paid so far',
                'check_in_date' => 'Arrival date',
                'check_out_date' => 'Departure date',
                'check_in_time' => 'Earliest arrival time',
                'check_out_time' => 'Latest departure time',
                'number_of_guests' => 'Number of guests',
                'nights' => 'Number of nights',
            ],
            'Property' => [
                'property.name' => 'The property name',
                'property.address' => 'The full address',
                'property.city' => 'The city',
                'property.country' => 'The country',
                'property.check_in_instructions' => 'Arrival instructions',
                'property.check_out_instructions' => 'Departure instructions',
                'property.house_rules' => 'House rules',
                'property.wifi_network' => 'Wi-Fi network name',
                'property.wifi_password' => 'Wi-Fi password',
                'property.door_code' => 'Door code',
                'property.directions' => 'How to find the property',
            ],
            'Organization' => [
                'organization.name' => 'Your company name',
                'organization.email' => 'Your contact email',
                'organization.phone' => 'Your contact phone number',
            ],
            'Links' => [
                'links.guest_portal' => 'Link to the guest portal',
                'links.payment' => 'Link to pay an outstanding balance',
                'links.review' => 'Link to leave a review',
            ],
        ];
    }

    /**
     * Every recognised placeholder name.
     *
     * @return list<string>
     */
    public static function allowedPlaceholders(): array
    {
        $names = [];

        foreach (self::vocabulary() as $group) {
            foreach (array_keys($group) as $name) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Render a template.
     *
     * @param  array<string, mixed>  $context  Extra values (links, portal tokens).
     * @param  bool  $html  Escape substituted values for HTML output.
     * @param  bool  $strict  Throw on an unknown placeholder instead of leaving it visible.
     */
    public function render(
        string $template,
        ?Reservation $reservation = null,
        ?Guest $guest = null,
        ?Property $property = null,
        array $context = [],
        bool $html = false,
        bool $strict = false,
    ): string {
        $values = $this->buildValues($reservation, $guest, $property, $context);

        // Matches {{ name }} and {{ name|modifier }} only. Anything that is
        // not a bare identifier — a function call, an index, an operator —
        // simply does not match and is left in place untouched.
        $pattern = '/\{\{\s*([a-z_]+(?:\.[a-z_]+)?)\s*(?:\|\s*([a-z_]+)\s*)?\}\}/i';

        $unknown = [];

        $rendered = preg_replace_callback(
            $pattern,
            function (array $matches) use ($values, $html, &$unknown): string {
                $name = strtolower($matches[1]);
                $modifier = isset($matches[2]) ? strtolower($matches[2]) : null;

                if (! array_key_exists($name, $values)) {
                    $unknown[] = $name;

                    // Left visible so the author can see what went wrong,
                    // rather than silently vanishing from a guest's email.
                    return $matches[0];
                }

                $value = $this->applyModifier($values[$name], $modifier);

                return $html ? e($value) : $value;
            },
            $template,
        ) ?? $template;

        if ($strict && $unknown !== []) {
            throw new TemplateRenderException(array_values(array_unique($unknown)));
        }

        return $rendered;
    }

    /**
     * Check a template without rendering it, for the editor's live validation.
     *
     * @return array{valid: bool, unknown: list<string>, used: list<string>}
     */
    public function validate(string $template): array
    {
        preg_match_all('/\{\{\s*([a-z_]+(?:\.[a-z_]+)?)\s*(?:\|[^}]*)?\}\}/i', $template, $matches);

        $used = array_map('strtolower', $matches[1] ?? []);
        $allowed = self::allowedPlaceholders();

        $unknown = array_values(array_unique(array_diff($used, $allowed)));

        return [
            'valid' => $unknown === [],
            'unknown' => $unknown,
            'used' => array_values(array_unique($used)),
        ];
    }

    /**
     * Resolve every placeholder to a string.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, string>
     */
    private function buildValues(
        ?Reservation $reservation,
        ?Guest $guest,
        ?Property $property,
        array $context,
    ): array {
        $guest ??= $reservation?->guest;
        $property ??= $reservation?->property;
        $organization = $property?->organization ?? $reservation?->organization;

        $values = [];

        if ($guest !== null) {
            $values += [
                'guest.first_name' => (string) $guest->first_name,
                'guest.last_name' => (string) $guest->last_name,
                'guest.full_name' => $guest->fullName(),
                'guest.email' => (string) $guest->email,
                'guest.phone' => (string) $guest->phone,
                'guest.country' => (string) $guest->country_code,
            ];
        }

        if ($reservation !== null) {
            $values += [
                'reservation.confirmation_code' => (string) $reservation->confirmation_code,
                'reservation.status' => $reservation->status->label(),
                'reservation.source' => (string) $reservation->source,
                'reservation.nights' => (string) $reservation->nights,
                'reservation.guests' => (string) $reservation->totalGuests(),
                'reservation.adults' => (string) $reservation->adults,
                'reservation.children' => (string) $reservation->children,
                'reservation.total' => $this->money($reservation->grandTotal()),
                'reservation.balance_due' => $this->money($reservation->balanceDue()),
                'reservation.amount_paid' => $this->money($reservation->paidTotal()),
                'check_in_date' => $reservation->check_in_date->toDateString(),
                'check_out_date' => $reservation->check_out_date->toDateString(),
                'check_in_time' => $reservation->arrivalMoment()->format('H:i'),
                'check_out_time' => $reservation->departureMoment()->format('H:i'),
                'number_of_guests' => (string) $reservation->totalGuests(),
                'nights' => (string) $reservation->nights,
            ];
        }

        if ($property !== null) {
            $values += [
                'property.name' => (string) $property->name,
                'property.address' => $this->formatAddress($property),
                'property.city' => (string) $property->city,
                'property.country' => (string) $property->country_code,
                'property.check_in_instructions' => (string) $property->check_in_instructions,
                'property.check_out_instructions' => (string) $property->check_out_instructions,
                'property.house_rules' => (string) $property->house_rules,
                'property.wifi_network' => (string) $property->wifi_network,
                'property.wifi_password' => (string) $property->wifi_password,
                'property.door_code' => (string) $property->door_code,
                'property.directions' => (string) $property->transit_description,
            ];
        }

        if ($organization !== null) {
            $values += [
                'organization.name' => (string) $organization->name,
                'organization.email' => (string) $organization->contact_email,
                'organization.phone' => (string) $organization->contact_phone,
            ];
        }

        // Caller-supplied values (portal links, payment links) are limited to
        // the same allow-list, so context cannot introduce new placeholders.
        foreach ($context as $key => $value) {
            $key = strtolower((string) $key);

            if (in_array($key, self::allowedPlaceholders(), true)) {
                $values[$key] = (string) $value;
            }
        }

        return $values;
    }

    /**
     * Apply a formatting modifier.
     */
    private function applyModifier(string $value, ?string $modifier): string
    {
        if ($modifier === null || $value === '') {
            return $value;
        }

        return match ($modifier) {
            'upper' => mb_strtoupper($value),
            'lower' => mb_strtolower($value),
            'title' => mb_convert_case($value, MB_CASE_TITLE, 'UTF-8'),

            // Date modifiers, applied only when the value parses as a date.
            'long' => $this->formatDate($value, 'l j F Y'),
            'short' => $this->formatDate($value, 'j M Y'),
            'day' => $this->formatDate($value, 'l'),
            'time' => $this->formatDate($value, 'H:i'),

            default => $value,
        };
    }

    private function formatDate(string $value, string $format): string
    {
        try {
            return CarbonImmutable::parse($value)->format($format);
        } catch (\Throwable) {
            return $value;
        }
    }

    private function money(Money $money): string
    {
        return $money->toDecimal().' '.$money->currency;
    }

    private function formatAddress(Property $property): string
    {
        return implode(', ', array_filter([
            $property->address_line_1,
            $property->address_line_2,
            $property->city,
            $property->postal_code,
            $property->country_code,
        ]));
    }
}
