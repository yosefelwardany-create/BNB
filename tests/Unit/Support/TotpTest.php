<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Domain\Users\Services\TotpService;
use PHPUnit\Framework\TestCase;

/**
 * Time-based one-time passwords.
 *
 * Checked against the test vectors printed in RFC 6238 itself, because a
 * home-grown TOTP that agrees with nothing is worse than none: it would accept
 * codes no authenticator app produces, and the symptom would be every user
 * believing their phone is broken.
 *
 * The vectors use the ASCII secret "12345678901234567890", which the RFC gives
 * as raw bytes — so it is base32-encoded here before use.
 */
class TotpTest extends TestCase
{
    private TotpService $totp;

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();

        $this->totp = new TotpService;
        $this->secret = $this->totp->base32Encode('12345678901234567890');
    }

    /**
     * RFC 6238, appendix B, the SHA-1 rows.
     */
    public function test_it_matches_the_rfc_test_vectors(): void
    {
        $vectors = [
            59 => '287082',
            1111111109 => '081804',
            1111111111 => '050471',
            1234567890 => '005924',
            2000000000 => '279037',
            20000000000 => '353130',
        ];

        foreach ($vectors as $time => $expected) {
            $this->assertSame(
                $expected,
                $this->totp->at($this->secret, intdiv($time, 30)),
                sprintf('The code at unix time %d should be %s.', $time, $expected),
            );
        }
    }

    public function test_base32_round_trips(): void
    {
        foreach (['', 'a', 'ab', 'abc', 'abcd', 'abcde', '12345678901234567890'] as $input) {
            $this->assertSame(
                $input,
                $this->totp->base32Decode($this->totp->base32Encode($input)),
                'Base32 did not survive a round trip.',
            );
        }
    }

    public function test_a_generated_secret_is_usable(): void
    {
        $secret = $this->totp->generateSecret();

        // 160 bits in base32 is 32 characters, which is what every
        // authenticator app expects to be handed.
        $this->assertSame(32, strlen($secret));
        $this->assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret);
        $this->assertTrue($this->totp->verify($secret, $this->totp->at($secret, intdiv(time(), 30))));
    }

    public function test_it_accepts_a_code_from_the_adjacent_step(): void
    {
        $now = 1_700_000_000;
        $step = intdiv($now, 30);

        // Clocks drift and people type slowly, so one step either side is
        // accepted.
        $this->assertTrue($this->totp->verify($this->secret, $this->totp->at($this->secret, $step - 1), $now));
        $this->assertTrue($this->totp->verify($this->secret, $this->totp->at($this->secret, $step), $now));
        $this->assertTrue($this->totp->verify($this->secret, $this->totp->at($this->secret, $step + 1), $now));
    }

    public function test_it_refuses_a_code_from_outside_the_window(): void
    {
        $now = 1_700_000_000;
        $step = intdiv($now, 30);

        // Two steps out is a minute old. Accepting it would double the useful
        // life of a code somebody shoulder-surfed.
        $this->assertFalse($this->totp->verify($this->secret, $this->totp->at($this->secret, $step - 2), $now));
        $this->assertFalse($this->totp->verify($this->secret, $this->totp->at($this->secret, $step + 2), $now));
    }

    public function test_it_refuses_malformed_input(): void
    {
        foreach (['', '12345', '1234567', 'abcdef', '  ', '000000x'] as $code) {
            $this->assertFalse(
                $this->totp->verify($this->secret, $code),
                sprintf('"%s" should not be accepted.', $code),
            );
        }
    }

    public function test_a_code_for_one_secret_does_not_work_for_another(): void
    {
        $other = $this->totp->generateSecret();
        $step = intdiv(time(), 30);

        $this->assertFalse($this->totp->verify($other, $this->totp->at($this->secret, $step)));
    }

    public function test_the_provisioning_uri_names_the_issuer_twice(): void
    {
        $uri = $this->totp->provisioningUri('ABCDEFGH', 'ana@example.test', 'Habitat PMS');

        // Once in the label and once as a parameter, because different apps read
        // different ones and an entry showing as a bare email among forty others
        // is one nobody can identify when they need it.
        $this->assertStringStartsWith('otpauth://totp/Habitat%20PMS:ana%40example.test?', $uri);
        $this->assertStringContainsString('issuer=Habitat%20PMS', $uri);
        $this->assertStringContainsString('secret=ABCDEFGH', $uri);
        $this->assertStringContainsString('digits=6', $uri);
        $this->assertStringContainsString('period=30', $uri);
    }

    public function test_recovery_codes_are_distinct_and_readable(): void
    {
        $codes = $this->totp->generateRecoveryCodes();

        $this->assertCount(10, $codes);
        $this->assertCount(10, array_unique($codes));

        foreach ($codes as $code) {
            // Two groups of five, so it can be read down a telephone.
            $this->assertMatchesRegularExpression('/^[a-z0-9]{5}-[a-z0-9]{5}$/', $code);
        }
    }
}
