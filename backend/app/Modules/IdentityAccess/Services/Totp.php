<?php

namespace App\Modules\IdentityAccess\Services;

use InvalidArgumentException;

/**
 * Mots de passe à usage unique basés sur le temps (TOTP, RFC 6238) — compatibles Google Authenticator,
 * Microsoft Authenticator, Authy… (SHA-1, 6 chiffres, période de 30 s).
 */
final class Totp
{
    public const DIGITS = 6;

    public const PERIOD = 30;

    /** Décalage d'horloge toléré, en périodes (± 30 s). */
    public const WINDOW = 1;

    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Secret aléatoire de 160 bits encodé en Base32. */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    /**
     * Vérifie un code et renvoie la période (time step) correspondante, ou null.
     * L'appelant refuse toute période déjà utilisée (anti-rejeu).
     */
    public static function verify(string $secret, string $code, ?int $timestamp = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code);

        if (! preg_match('/^\d{'.self::DIGITS.'}$/', $code)) {
            return null;
        }

        $step = intdiv($timestamp ?? time(), self::PERIOD);

        for ($offset = -self::WINDOW; $offset <= self::WINDOW; $offset++) {
            if (hash_equals(self::codeAt($secret, $step + $offset), $code)) {
                return $step + $offset;
            }
        }

        return null;
    }

    public static function codeAt(string $secret, int $step, string $algorithm = 'sha1', int $digits = self::DIGITS): string
    {
        $hash = hash_hmac($algorithm, pack('J', $step), self::base32Decode($secret), true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);

        return str_pad((string) ($binary % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    /** URI à transformer en QR code par le frontend. */
    public static function provisioningUri(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/'.rawurlencode($issuer).':'.rawurlencode($account).'?'.http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public static function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';
        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= self::BASE32[bindec(str_pad($chunk, 5, '0'))];
        }

        return $encoded;
    }

    public static function base32Decode(string $encoded): string
    {
        $encoded = strtoupper(rtrim($encoded, '='));
        $bits = '';

        foreach (str_split($encoded) as $char) {
            $value = strpos(self::BASE32, $char);
            if ($value === false) {
                throw new InvalidArgumentException('Secret Base32 invalide.');
            }
            $bits .= str_pad(decbin($value), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $bytes .= chr(bindec($byte));
            }
        }

        return $bytes;
    }
}
