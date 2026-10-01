<?php

declare(strict_types=1);

namespace App\Domain\Agents\Support;

use App\Domain\Agents\Exceptions\BotEndpointRefusedException;

/**
 * A URL this platform is willing to send a property's facts to.
 *
 * Every operator can type a URL here and the server will fetch it, which is a
 * request forgery primitive handed to a customer. Left unchecked, one tenant
 * could point an agent at `http://169.254.169.254/` and have Habitat read the
 * host's cloud credentials back to them, or sweep an internal network by
 * watching which addresses answer and how quickly. The platform is
 * multi-tenant, so that is not a hypothetical shaped like a privacy question —
 * it is one customer reading another's infrastructure.
 *
 * So: TLS only, and no address belonging to the machine or the network it sits
 * on. Both are relaxable by configuration for local development, and neither is
 * relaxed by anything a request can carry.
 *
 * **What this does not close**, stated rather than implied: the name is resolved
 * here and resolved again by the HTTP client, so a nameserver that answers
 * differently the second time can still land the connection on a private
 * address. Closing that needs the resolved address pinned into the connection,
 * which the client does not expose portably. The exposure is one operator of one
 * tenant reaching one internal host with a POST whose body they already know and
 * whose response the agent screen shows them — worth knowing about, and a long
 * way from the metadata endpoint this does close.
 */
final class BotEndpoint
{
    private function __construct(public readonly string $url, public readonly string $host) {}

    /**
     * @throws BotEndpointRefusedException when the URL may not be called
     */
    public static function parse(string $url): self
    {
        $url = trim($url);
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new BotEndpointRefusedException('That is not a complete URL. It needs to look like https://bots.example.com/yellow.');
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);

        /*
         * Both checks run, and both reasons are reported.
         *
         * Refusing on the scheme alone and stopping would tell somebody who typed
         * `http://10.0.0.5/bot` to use https, and the https version is refused
         * too — for the other reason. One round trip per problem is how a form
         * wastes an afternoon.
         */
        $reasons = [];

        if (! in_array($scheme, self::allowedSchemes(), true)) {
            $reasons[] = 'it has to be https, because the property’s facts travel in this request '
                .'— including a door code where the booking is entitled to one';
        }

        foreach (self::addressesOf($host) as $address) {
            if (self::isReserved($address)) {
                $reasons[] = sprintf(
                    '%s resolves to %s, which is on this server’s own network rather than somewhere '
                    .'Habitat reaches from the outside',
                    $host,
                    $address,
                );

                break;
            }
        }

        if ($reasons !== []) {
            throw new BotEndpointRefusedException(sprintf(
                'Habitat will not call that endpoint: %s.',
                implode('; and ', $reasons),
            ));
        }

        return new self($url, $host);
    }

    /**
     * Whether a URL is callable, without raising.
     */
    public static function permits(string $url): bool
    {
        try {
            self::parse($url);

            return true;
        } catch (BotEndpointRefusedException) {
            return false;
        }
    }

    /**
     * Every address the host resolves to, or the literal when it is one.
     *
     * All of them are checked, not just the first: a name that answers with a
     * public address and a private one is the cheapest way past a check that
     * looks at one record.
     *
     * @return list<string>
     */
    private static function addressesOf(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        // Bracketed IPv6 literal, as parse_url hands it back.
        $unbracketed = trim($host, '[]');

        if (filter_var($unbracketed, FILTER_VALIDATE_IP) !== false) {
            return [$unbracketed];
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        if ($records === false || $records === []) {
            // A name that does not resolve is refused by the request itself
            // later, with the client's own error. Refusing it here would also
            // refuse a name that is merely slow or behind split-horizon DNS.
            return [];
        }

        $addresses = [];

        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;

            if (is_string($address)) {
                $addresses[] = $address;
            }
        }

        return $addresses;
    }

    /**
     * Loopback, link-local (which is where cloud metadata lives), private
     * ranges and anything else not routable on the public internet.
     */
    private static function isReserved(string $address): bool
    {
        if (self::insecureAllowed()) {
            return false;
        }

        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) === false;
    }

    /**
     * @return list<string>
     */
    private static function allowedSchemes(): array
    {
        return self::insecureAllowed() ? ['https', 'http'] : ['https'];
    }

    /**
     * Only ever true from configuration, so that a request cannot turn it on.
     */
    private static function insecureAllowed(): bool
    {
        return (bool) config('pms.agents.bot.allow_insecure', false);
    }
}
