<?php

declare(strict_types=1);

namespace App\Domain\Users\Services;

use Illuminate\Support\Str;
use Tests\Unit\Support\TotpTest;

/**
 * Time-based one-time passwords, per RFC 6238.
 *
 * Implemented here rather than pulled in, because the algorithm is forty lines
 * of HMAC and arithmetic that has not changed since 2011, and because this code
 * stands between an attacker with a stolen password and somebody's business —
 * so it is worth being able to read all of it. {@see TotpTest}
 * checks it against the test vectors in the RFC itself.
 *
 * Three decisions that are security-relevant rather than stylistic:
 *
 *  - **SHA-1, six digits, thirty seconds.** Not because they are the strongest
 *    available, but because every authenticator app implements exactly this and
 *    a stronger variant nobody can enrol protects nobody. The known weaknesses
 *    of SHA-1 are collision attacks, which do not apply to HMAC.
 *
 *  - **A window of one step either side.** Clocks drift and people type slowly.
 *    Wider would meaningfully extend the life of an intercepted code.
 *
 *  - **Constant-time comparison.** A comparison that returns early leaks how
 *    much of the code was right, which turns a million guesses into a thousand.
 */
class TotpService
{
    private const ALGORITHM = 'sha1';

    private const DIGITS = 6;

    private const PERIOD = 30;

    /** How many steps either side of now are accepted. */
    private const WINDOW = 1;

    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * A fresh shared secret, base32-encoded as authenticator apps expect.
     *
     * 160 bits, which is the RFC's recommendation for SHA-1 and what the
     * HMAC's block size makes free.
     */
    public function generateSecret(): string
    {
        return $this->base32Encode(random_bytes(20));
    }

    /**
     * The URI an authenticator app reads from a QR code.
     *
     * The issuer appears twice — once as a label prefix and once as a parameter
     * — because different apps read different ones, and an entry that shows up
     * as a bare email address among forty others is one nobody can identify
     * when they need it.
     */
    public function provisioningUri(string $secret, string $account, string $issuer): string
    {
        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=%s&digits=%d&period=%d',
            rawurlencode($issuer),
            rawurlencode($account),
            $secret,
            rawurlencode($issuer),
            strtoupper(self::ALGORITHM),
            self::DIGITS,
            self::PERIOD,
        );
    }

    /**
     * Whether a code is valid for a secret right now.
     *
     * @param  int|null  $at  Unix time to check against; null means now.
     */
    public function verify(string $secret, string $code, ?int $at = null): bool
    {
        $code = preg_replace('/\D/', '', $code) ?? '';

        if (strlen($code) !== self::DIGITS) {
            return false;
        }

        $counter = intdiv($at ?? time(), self::PERIOD);

        for ($offset = -self::WINDOW; $offset <= self::WINDOW; $offset++) {
            // Every candidate is compared, and the loop does not break early on
            // a match: returning as soon as one succeeds would make a code from
            // the previous step measurably faster to reject than one from the
            // next, which is a (small) oracle on the server's clock.
            if (hash_equals($this->at($secret, $counter + $offset), $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The code for a secret at a particular counter step.
     */
    public function at(string $secret, int $counter): string
    {
        $key = $this->base32Decode($secret);

        // The counter as eight bytes, big-endian. `J` is unsigned 64-bit
        // big-endian, which is exactly what the RFC specifies.
        $binary = pack('J', $counter);

        $hash = hash_hmac(self::ALGORITHM, $binary, $key, true);

        // Dynamic truncation: the low nibble of the last byte picks where in
        // the hash to read four bytes from, and the top bit is masked off so
        // the result is positive on every platform.
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;

        $value = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad(
            (string) ($value % (10 ** self::DIGITS)),
            self::DIGITS,
            '0',
            STR_PAD_LEFT,
        );
    }

    /**
     * Single-use codes for somebody who has lost their phone.
     *
     * Ten of them, formatted in two groups so they can be read aloud down a
     * telephone without ambiguity. Returned in clear exactly once; only hashes
     * are stored.
     *
     * @return list<string>
     */
    public function generateRecoveryCodes(int $count = 10): array
    {
        $codes = [];

        for ($i = 0; $i < $count; $i++) {
            $codes[] = strtolower(Str::random(5).'-'.Str::random(5));
        }

        return $codes;
    }

    /**
     * Base32, RFC 4648, uppercase and unpadded.
     */
    public function base32Encode(string $bytes): string
    {
        $bits = '';

        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';

        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= self::BASE32_ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        return $encoded;
    }

    public function base32Decode(string $secret): string
    {
        // People retype secrets with spaces and padding, and some apps lower-case
        // them. Normalising here costs nothing and turns a puzzling rejection
        // into a working enrolment.
        $secret = strtoupper(str_replace([' ', '=', '-'], '', $secret));

        $bits = '';

        foreach (str_split($secret) as $character) {
            $index = strpos(self::BASE32_ALPHABET, $character);

            if ($index === false) {
                continue;
            }

            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';

        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $bytes .= chr(bindec($chunk));
            }
        }

        return $bytes;
    }
}
