<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Support;

use App\Support\Money\Money;

/** Documented Hostex v3 fields. Missing money is never a zero or a default currency. */
final class HostexData
{
    public static function currency(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[A-Za-z]{3}$/D', $value)
            && ! in_array(strtoupper($value), ['XXX', 'XTS'], true)
            ? strtoupper($value) : null;
    }

    public static function text(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    /** Retain unverified decimals without inventing a denomination. */
    public static function amount(mixed $amount, mixed $currency): ?array
    {
        if (! is_scalar($amount) || ! preg_match('/^-?\d+(\.\d+)?$/D', (string) $amount)) {
            return null;
        }
        $currency = self::currency($currency);

        return $currency === null
            ? ['amount' => null, 'currency' => null, 'formatted' => (string) $amount]
            : Money::fromDecimal((string) $amount, $currency)->jsonSerialize();
    }

    public static function money(mixed $value): ?array
    {
        return is_array($value) ? self::amount($value['amount'] ?? null, $value['currency'] ?? null) : null;
    }

    /** Only these public image URLs are handed to a browser; no server-side requests. */
    public static function imageUrl(mixed $value): ?string
    {
        if (! is_string($value) || strlen($value) > 4096 || ! filter_var($value, FILTER_VALIDATE_URL)) {
            return null;
        }
        $url = parse_url($value);
        $host = strtolower($url['host'] ?? '');
        if (($url['scheme'] ?? '') !== 'https' || isset($url['user']) || isset($url['pass'])
            || $host === 'localhost' || ! str_contains($host, '.')
            || (filter_var($host, FILTER_VALIDATE_IP) && ! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE))) {
            return null;
        }

        return $value;
    }

    /**
     * Only used inside the documented image container. Its schema is explicitly
     * variable: inspect URL values, never guess channel-specific field names.
     * Several different assets in one entry are ambiguous and remain unsupported.
     */
    public static function pictureUrl(mixed $value): ?string
    {
        // Observed Hostex/Airbnb gallery shape: one asset with several sizes.
        // The cover is the same object encoded as JSON. Prefer its original,
        // rather than rejecting the size variants as different photographs.
        if (is_string($value) && strlen($value) <= 65536 && str_starts_with(trim($value), '{')) {
            $decoded = json_decode($value, true, 8);
            if (is_array($decoded)) {
                $value = $decoded;
            }
        }
        if (is_array($value) && isset($value['original_url'])
            && self::imageUrl($value['original_url']) !== null) {
            return $value['original_url'];
        }

        $urls = [];
        $pending = [[$value, 0]];
        $visited = 0;
        while ($pending !== []) {
            [$entry, $depth] = array_pop($pending);
            if (++$visited > 500) {
                return null;
            }
            if (is_array($entry)) {
                if ($depth >= 4 || count($entry) > 100) {
                    return null;
                }
                foreach ($entry as $child) {
                    $pending[] = [$child, $depth + 1];
                }
            } elseif (is_string($entry)) {
                $entry = trim($entry);
                if (str_starts_with($entry, '//')) {
                    $entry = 'https:'.$entry;
                } elseif (str_starts_with($entry, 'http://')) {
                    $entry = 'https://'.substr($entry, 7);
                }
                if (self::imageUrl($entry) !== null) {
                    $urls[$entry] = true;
                }
                if (count($urls) > 1) {
                    return null;
                }
            }
        }

        return count($urls) === 1 ? array_key_first($urls) : null;
    }

    /** No identity documents, access codes, or raw provider payloads are persisted. */
    public static function financials(array $row): array
    {
        $rates = is_array($row['rates'] ?? null) ? $row['rates'] : [];
        $result = [];
        foreach (['rate', 'total_rate', 'commission', 'total_commission', 'tax'] as $key) {
            if (array_key_exists($key, $rates)) {
                $result[$key] = self::money($rates[$key]);
            }
        }
        if (isset($rates['details']) && is_array($rates['details'])) {
            $result['details'] = array_values(array_map(fn (array $detail): array => [
                'type' => self::text($detail['type'] ?? null),
                'description' => self::text($detail['description'] ?? null),
                'money' => self::money($detail),
            ], array_filter($rates['details'], 'is_array')));
        }
        if (isset($row['payment']) && is_array($row['payment'])) {
            $payment = $row['payment'];
            $result['payment'] = ['scope' => 'order'];
            if (array_key_exists('status', $payment)) {
                $result['payment']['status'] = self::text($payment['status']);
            }
            foreach (['total_amount', 'received_amount', 'balance_amount'] as $key) {
                if (array_key_exists($key, $payment)) {
                    $result['payment'][$key] = self::amount($payment[$key], $payment['currency'] ?? null);
                }
            }
        }
        if (isset($row['additional_fees']) && is_array($row['additional_fees'])) {
            $result['additional_fees'] = array_values(array_map(fn (array $fee): array => [
                'name' => self::text($fee['name'] ?? null), 'money' => self::money($fee),
            ], array_filter($row['additional_fees'], 'is_array')));
        }

        return $result;
    }

    public static function detailTotal(array $financials, string $type): ?array
    {
        $details = array_values(array_filter($financials['details'] ?? [], fn ($d) => $d['type'] === $type));
        if ($details === []) {
            return null;
        }
        $currency = $details[0]['money']['currency'] ?? null;
        $total = 0;
        foreach ($details as $detail) {
            if ($currency === null || ($detail['money']['currency'] ?? null) !== $currency || ! isset($detail['money']['amount'])) {
                return null;
            }
            $total += $detail['money']['amount'];
        }

        return Money::of($total, $currency)->jsonSerialize();
    }
}
