<?php

declare(strict_types=1);

namespace App\Domain\Websites\Support;

/**
 * A website's address as browsers compare them: scheme, host and port.
 *
 * Framing permission (`frame-ancestors`) and the loader's checks both work
 * on origins, so a store address is kept as one: https only, lowercase, no
 * path, and nothing a typed address might carry along by accident.
 */
final class SiteOrigin
{
    /**
     * The origin a typed store address stands for, or null when it is not
     * just an https origin. A trailing "/" is allowed; anything after it is
     * not, because a path in a frame policy would not mean what it looks like.
     */
    public static function normalize(string $value): ?string
    {
        $parts = parse_url(trim($value));

        if ($parts === false || ! self::isOriginOnly($parts) || ! in_array($parts['path'] ?? '', ['', '/'], true)) {
            return null;
        }

        return self::build($parts);
    }

    /**
     * The origin of any https address, such as a home's page on the store,
     * or null when it is not one.
     */
    public static function of(string $url): ?string
    {
        $parts = parse_url(trim($url));

        if ($parts === false || ! self::isOriginOnly($parts, allowQuery: true)) {
            return null;
        }

        return self::build($parts);
    }

    /**
     * @param  array<string, int|string>  $parts
     */
    private static function isOriginOnly(array $parts, bool $allowQuery = false): bool
    {
        return strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/i', (string) ($parts['host'] ?? '')) === 1
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && ($allowQuery || (! isset($parts['query']) && ! isset($parts['fragment'])));
    }

    /**
     * @param  array<string, int|string>  $parts
     */
    private static function build(array $parts): string
    {
        $port = isset($parts['port']) && (int) $parts['port'] !== 443 ? ':'.(int) $parts['port'] : '';

        return 'https://'.strtolower((string) $parts['host']).$port;
    }
}
