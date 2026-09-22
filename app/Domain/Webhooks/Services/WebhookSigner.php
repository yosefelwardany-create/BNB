<?php

declare(strict_types=1);

namespace App\Domain\Webhooks\Services;

/**
 * Signs outbound payloads, and verifies inbound ones.
 *
 * The scheme is the one every serious webhook sender converges on, and each
 * part of it is load-bearing:
 *
 *  - **The timestamp is inside the signed string**, not merely sent alongside
 *    it. If it were outside, an attacker could replay yesterday's body with
 *    today's timestamp and the signature would still verify.
 *  - **Verification is time-bounded.** A valid signature stays valid forever
 *    unless the receiver refuses old timestamps, which turns any captured
 *    request into a permanent replay.
 *  - **Comparison is constant-time.** A naive `===` on a signature leaks, byte
 *    by byte, how much of a guess was right, and that is enough to forge one.
 *
 * The header format is `t=<unix>,v1=<hex hmac>`. The version tag is there so a
 * future algorithm can be introduced by adding `v2=` alongside `v1=` rather
 * than by a flag day that breaks every existing receiver.
 */
class WebhookSigner
{
    public const HEADER = 'X-Habitat-Signature';

    public const VERSION = 'v1';

    /**
     * The value of the signature header for a payload.
     */
    public function sign(string $payload, string $secret, ?int $timestamp = null): string
    {
        $timestamp ??= time();

        return sprintf(
            't=%d,%s=%s',
            $timestamp,
            self::VERSION,
            $this->compute($payload, $secret, $timestamp),
        );
    }

    /**
     * Whether a header was produced by somebody holding the secret, recently.
     *
     * Both halves matter. Without the secret nobody can produce a valid
     * signature; without the tolerance, anybody who once captured a valid
     * request can replay it forever.
     */
    public function verify(
        string $payload,
        string $header,
        string $secret,
        int $toleranceSeconds = 300,
    ): bool {
        $parts = $this->parse($header);

        if ($parts === null) {
            return false;
        }

        [$timestamp, $signature] = $parts;

        if (abs(time() - $timestamp) > $toleranceSeconds) {
            return false;
        }

        // Constant-time: a byte-by-byte comparison tells an attacker how much
        // of their guess was right, which is all they need to finish it.
        return hash_equals($this->compute($payload, $secret, $timestamp), $signature);
    }

    /**
     * The HMAC over the timestamp and the body together.
     *
     * The timestamp is part of the signed string, not an adjacent header. That
     * is the whole defence against replay: a captured body cannot be re-sent
     * with a fresh timestamp, because the signature covers both.
     */
    private function compute(string $payload, string $secret, int $timestamp): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
    }

    /**
     * @return array{0: int, 1: string}|null
     */
    private function parse(string $header): ?array
    {
        $timestamp = null;
        $signature = null;

        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part), 2);

            if (count($pair) !== 2) {
                continue;
            }

            [$key, $value] = $pair;

            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            }

            if ($key === self::VERSION) {
                $signature = $value;
            }
        }

        if ($timestamp === null || $signature === null) {
            return null;
        }

        return [$timestamp, $signature];
    }
}
