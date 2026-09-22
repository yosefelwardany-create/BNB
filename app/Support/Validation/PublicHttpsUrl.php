<?php

declare(strict_types=1);

namespace App\Support\Validation;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A URL our server may be told to fetch.
 *
 * A webhook URL is supplied by a user and requested by our server, which is
 * precisely the shape of a server-side request forgery. Without a constraint,
 * anybody who can register an endpoint can make the platform issue requests to
 * addresses only the platform can reach: the metadata service on a cloud
 * instance, a database admin panel on the private network, a service bound to
 * localhost.
 *
 * So: HTTPS only, and no address that resolves inside the infrastructure.
 *
 * This is a first line of defence, not the whole of one. A hostname that
 * resolves publicly now can resolve privately later — the classic DNS rebind —
 * so a deployment that takes this seriously also egress-filters the worker
 * that makes the request. Checking here is still worth doing: it catches the
 * overwhelming majority of cases, including every accidental one, at the point
 * where a human can be told what is wrong.
 */
class PublicHttpsUrl implements ValidationRule
{
    /**
     * Ranges that are never a legitimate webhook destination.
     *
     * @var list<string>
     */
    private const BLOCKED_V4 = [
        '0.0.0.0/8',        // "this host"
        '10.0.0.0/8',       // private
        '127.0.0.0/8',      // loopback
        '169.254.0.0/16',   // link-local, and the cloud metadata service
        '172.16.0.0/12',    // private
        '192.168.0.0/16',   // private
        '100.64.0.0/10',    // carrier-grade NAT
        '192.0.0.0/24',     // IETF protocol assignments
        '198.18.0.0/15',    // benchmarking
        '224.0.0.0/4',      // multicast
        '240.0.0.0/4',      // reserved
    ];

    public function __construct(private readonly bool $allowLocal = false) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('The :attribute must be a URL.');

            return;
        }

        $parts = parse_url($value);

        if ($parts === false || ! isset($parts['host'])) {
            $fail('The :attribute must be a valid URL.');

            return;
        }

        if (($parts['scheme'] ?? null) !== 'https') {
            $fail('The :attribute must use https. Payloads carry booking and guest data, and http sends them in clear.');

            return;
        }

        // Local destinations are allowed only where a test or a development
        // environment has explicitly asked for it.
        if ($this->allowLocal) {
            return;
        }

        $host = $parts['host'];

        foreach ($this->addressesFor($host) as $address) {
            if ($this->isBlocked($address)) {
                $fail('The :attribute must be reachable on the public internet. Private and loopback addresses are not accepted.');

                return;
            }
        }
    }

    /**
     * Every address the host resolves to.
     *
     * All of them are checked, not just the first: a hostname with one public
     * and one private A record would otherwise pass while still being usable
     * to reach the private one.
     *
     * @return list<string>
     */
    private function addressesFor(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

        $addresses = [];

        foreach ($records as $record) {
            $addresses[] = $record['ip'] ?? $record['ipv6'] ?? null;
        }

        // A host that resolves to nothing is allowed through, deliberately.
        // It cannot be reached, so it is not an SSRF risk; and refusing it
        // would make registering an endpoint fail during any DNS hiccup, or
        // whenever this process happens to have no resolver — a validation
        // rule that depends on the network being healthy fails people for
        // reasons that have nothing to do with what they typed. A host that
        // never resolves simply produces failed deliveries, which the
        // delivery log then explains.
        return array_values(array_filter($addresses));
    }

    private function isBlocked(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            // ::1 is loopback; fc00::/7 is unique-local; fe80::/10 is
            // link-local. The normalised form makes the prefix check safe.
            $normalised = strtolower(inet_ntop(inet_pton($address)));

            return $normalised === '::1'
                || str_starts_with($normalised, 'fc')
                || str_starts_with($normalised, 'fd')
                || str_starts_with($normalised, 'fe8')
                || str_starts_with($normalised, 'fe9')
                || str_starts_with($normalised, 'fea')
                || str_starts_with($normalised, 'feb');
        }

        foreach (self::BLOCKED_V4 as $range) {
            if ($this->inRange($address, $range)) {
                return true;
            }
        }

        return false;
    }

    private function inRange(string $address, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);

        $ip = ip2long($address);
        $net = ip2long($subnet);

        if ($ip === false || $net === false) {
            return false;
        }

        $mask = -1 << (32 - (int) $bits);

        return ($ip & $mask) === ($net & $mask);
    }
}
