<?php

namespace Tests\Unit\Modules\IdentityAccess;

use App\Modules\IdentityAccess\Services\Totp;
use PHPUnit\Framework\TestCase;

class TotpTest extends TestCase
{
    /** Secret de la RFC 6238 (annexe B) : "12345678901234567890" en ASCII. */
    private const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    public function test_base32_round_trip(): void
    {
        $this->assertSame('12345678901234567890', Totp::base32Decode(self::RFC_SECRET));
        $this->assertSame(self::RFC_SECRET, Totp::base32Encode('12345678901234567890'));
    }

    /** Vecteurs de test officiels de la RFC 6238 (SHA-1, 8 chiffres). */
    public function test_rfc6238_test_vectors(): void
    {
        $vectors = [
            59 => '94287082',
            1111111109 => '07081804',
            1111111111 => '14050471',
            1234567890 => '89005924',
            2000000000 => '69279037',
            20000000000 => '65353130',
        ];

        foreach ($vectors as $time => $expected) {
            $this->assertSame($expected, Totp::codeAt(self::RFC_SECRET, intdiv($time, 30), 'sha1', 8), "t={$time}");
        }
    }

    public function test_verify_accepts_current_and_adjacent_periods_only(): void
    {
        $secret = Totp::generateSecret();
        $now = 1_800_000_000;
        $step = intdiv($now, Totp::PERIOD);

        $this->assertSame($step, Totp::verify($secret, Totp::codeAt($secret, $step), $now));
        $this->assertSame($step - 1, Totp::verify($secret, Totp::codeAt($secret, $step - 1), $now));
        $this->assertSame($step + 1, Totp::verify($secret, Totp::codeAt($secret, $step + 1), $now));
        $this->assertNull(Totp::verify($secret, Totp::codeAt($secret, $step - 2), $now));
    }

    public function test_verify_rejects_malformed_codes(): void
    {
        $secret = Totp::generateSecret();

        $this->assertNull(Totp::verify($secret, 'abcdef'));
        $this->assertNull(Totp::verify($secret, '12345'));
        $this->assertNull(Totp::verify($secret, ''));
    }

    public function test_generated_secret_is_160_bits(): void
    {
        $this->assertSame(20, strlen(Totp::base32Decode(Totp::generateSecret())));
        $this->assertNotSame(Totp::generateSecret(), Totp::generateSecret());
    }

    public function test_provisioning_uri(): void
    {
        $uri = Totp::provisioningUri('ABC', 'admin@example.com', 'REYEL 16 PARYAJ');

        $this->assertStringStartsWith('otpauth://totp/REYEL%2016%20PARYAJ:admin%40example.com?secret=ABC&issuer=REYEL%2016%20PARYAJ', $uri);
    }
}
