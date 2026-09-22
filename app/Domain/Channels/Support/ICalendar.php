<?php

declare(strict_types=1);

namespace App\Domain\Channels\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * A focused RFC 5545 reader and writer covering the subset that vacation
 * rental calendars actually use: VEVENT with DTSTART, DTEND, UID, SUMMARY and
 * DESCRIPTION.
 *
 * A general-purpose calendar library would bring a great deal of functionality
 * the platform never exercises. The rules that matter here are narrow and
 * worth owning outright:
 *
 *  - Line unfolding must happen before parsing, because every real feed folds
 *    long lines at 75 octets.
 *  - DTEND is exclusive, which matches a checkout date exactly.
 *  - All-day values (VALUE=DATE) have no time component and must not be
 *    shifted by a timezone conversion, or a booking moves by a day.
 */
final class ICalendar
{
    /**
     * Parse an iCalendar document into its VEVENTs.
     *
     * @return list<ICalendarEvent>
     */
    public static function parse(string $contents): array
    {
        $lines = self::unfold($contents);

        $events = [];
        $current = null;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === 'BEGIN:VEVENT') {
                $current = [];

                continue;
            }

            if ($trimmed === 'END:VEVENT') {
                if ($current !== null) {
                    $event = self::buildEvent($current);

                    if ($event !== null) {
                        $events[] = $event;
                    }
                }

                $current = null;

                continue;
            }

            if ($current === null) {
                continue;
            }

            [$name, $parameters, $value] = self::parseLine($line);

            if ($name === null) {
                continue;
            }

            $current[$name] = ['parameters' => $parameters, 'value' => $value];
        }

        return $events;
    }

    /**
     * Render a set of events as an iCalendar document.
     *
     * @param  list<ICalendarEvent>  $events
     */
    public static function build(array $events, string $calendarName, string $productId = '-//Habitat PMS//EN'): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:'.$productId,
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:'.self::escape($calendarName),
        ];

        foreach ($events as $event) {
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:'.$event->uid;
            $lines[] = 'DTSTAMP:'.gmdate('Ymd\THis\Z');
            $lines[] = 'DTSTART;VALUE=DATE:'.$event->start->format('Ymd');
            // DTEND is exclusive: for a stay this is the checkout date.
            $lines[] = 'DTEND;VALUE=DATE:'.$event->end->format('Ymd');
            $lines[] = 'SUMMARY:'.self::escape($event->summary);

            if ($event->description !== null) {
                $lines[] = 'DESCRIPTION:'.self::escape($event->description);
            }

            if ($event->status !== null) {
                $lines[] = 'STATUS:'.$event->status;
            }

            $lines[] = 'END:VEVENT';
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map(self::fold(...), $lines))."\r\n";
    }

    /**
     * Undo RFC 5545 line folding: a CRLF followed by a space or tab is a
     * continuation of the previous line.
     *
     * @return list<string>
     */
    private static function unfold(string $contents): array
    {
        $normalised = str_replace(["\r\n", "\r"], "\n", $contents);
        $normalised = preg_replace('/\n[ \t]/', '', $normalised) ?? $normalised;

        return array_values(array_filter(explode("\n", $normalised), fn (string $l): bool => $l !== ''));
    }

    /**
     * Fold a line at 75 octets, as required by the specification.
     */
    private static function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }

        $parts = [substr($line, 0, 75)];
        $rest = substr($line, 75);

        while (strlen($rest) > 74) {
            $parts[] = ' '.substr($rest, 0, 74);
            $rest = substr($rest, 74);
        }

        if ($rest !== '') {
            $parts[] = ' '.$rest;
        }

        return implode("\r\n", $parts);
    }

    /**
     * @return array{0: ?string, 1: array<string, string>, 2: string}
     */
    private static function parseLine(string $line): array
    {
        $separator = strpos($line, ':');

        if ($separator === false) {
            return [null, [], ''];
        }

        $namePart = substr($line, 0, $separator);
        $value = substr($line, $separator + 1);

        $segments = explode(';', $namePart);
        $name = strtoupper(array_shift($segments));

        $parameters = [];

        foreach ($segments as $segment) {
            if (! str_contains($segment, '=')) {
                continue;
            }

            [$key, $parameterValue] = explode('=', $segment, 2);
            $parameters[strtoupper($key)] = trim($parameterValue, '"');
        }

        return [$name, $parameters, self::unescape($value)];
    }

    /**
     * @param  array<string, array{parameters: array<string, string>, value: string}>  $properties
     */
    private static function buildEvent(array $properties): ?ICalendarEvent
    {
        $start = self::parseDate($properties['DTSTART'] ?? null);

        if ($start === null) {
            return null;
        }

        $end = self::parseDate($properties['DTEND'] ?? null);

        if ($end === null) {
            // A VEVENT without DTEND is a single day in this context.
            $end = $start->modify('+1 day');
        }

        return new ICalendarEvent(
            uid: $properties['UID']['value'] ?? hash('sha256', $start->format('Ymd').($properties['SUMMARY']['value'] ?? '')),
            start: $start,
            end: $end,
            summary: $properties['SUMMARY']['value'] ?? 'Blocked',
            description: $properties['DESCRIPTION']['value'] ?? null,
            status: $properties['STATUS']['value'] ?? null,
        );
    }

    /**
     * @param  array{parameters: array<string, string>, value: string}|null  $property
     */
    private static function parseDate(?array $property): ?DateTimeImmutable
    {
        if ($property === null) {
            return null;
        }

        $value = trim($property['value']);

        // All-day form: YYYYMMDD with no time. Parsed in UTC deliberately —
        // applying a timezone offset to a date-only value is what makes
        // imported bookings drift by a day.
        if (preg_match('/^\d{8}$/', $value)) {
            return DateTimeImmutable::createFromFormat(
                'Ymd|',
                $value,
                new DateTimeZone('UTC'),
            ) ?: null;
        }

        // UTC form: YYYYMMDDTHHMMSSZ
        if (preg_match('/^\d{8}T\d{6}Z$/', $value)) {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        }

        // Local form, optionally with a TZID parameter.
        if (preg_match('/^\d{8}T\d{6}$/', $value)) {
            $timezone = $property['parameters']['TZID'] ?? 'UTC';

            try {
                return new DateTimeImmutable($value, new DateTimeZone($timezone));
            } catch (\Exception) {
                return new DateTimeImmutable($value, new DateTimeZone('UTC'));
            }
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    private static function escape(string $value): string
    {
        return str_replace(
            ['\\', "\n", "\r", ';', ','],
            ['\\\\', '\\n', '', '\\;', '\\,'],
            $value,
        );
    }

    private static function unescape(string $value): string
    {
        return str_replace(
            ['\\n', '\\N', '\\,', '\\;', '\\\\'],
            ["\n", "\n", ',', ';', '\\'],
            $value,
        );
    }
}
