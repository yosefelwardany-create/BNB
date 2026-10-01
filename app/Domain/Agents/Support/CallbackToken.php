<?php

declare(strict_types=1);

namespace App\Domain\Agents\Support;

use Illuminate\Support\Str;

/**
 * The one-time secret a bot answers with.
 *
 * An inbound callback has no account behind it. The bot is somebody's agent run
 * on somebody else's infrastructure; it has no session, no API key and no user,
 * and the only thing distinguishing it from anybody else who finds the URL is
 * this token. So the token *is* the authorisation, and it carries exactly one
 * power: writing the answer to the one ask it was issued for. It cannot read a
 * property, list anything, or answer a second question.
 *
 * Issued and stored as a pair. The plain token goes out once, in the webhook, and
 * is never stored or logged; only its hash is written down. That is the
 * difference from the guest portal's token, which lives in the clear because an
 * operator legitimately needs to read it back to resend a link. Nobody ever needs
 * to read this one, which makes hashing it free — and a database dump, a replica
 * or a stray `select *` then contains nothing that can answer on a bot's behalf.
 *
 * sha256 rather than a password hash on purpose. The input is 64 random
 * characters from a CSPRNG, not a human's choice, so there is no dictionary to
 * stretch against — and the lookup happens on an indexed column, which bcrypt
 * would make impossible without reading every open row.
 */
final class CallbackToken
{
    private function __construct(
        /** Sent once, in the webhook. Never stored. */
        public readonly string $plain,
        /** What goes in the database. */
        public readonly string $hash,
    ) {}

    public static function issue(): self
    {
        // Long enough that the rate limit on the callback route is a formality
        // rather than the only thing standing between a guesser and an answer.
        $plain = Str::random(64);

        return new self($plain, self::hash($plain));
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', trim($plain));
    }
}
