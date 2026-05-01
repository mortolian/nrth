<?php

namespace App\Support;

/**
 * PayFast form and ITN signature (MD5 over sorted key=value pairs).
 *
 * @see https://developers.payfast.co.za/docs#step_3_confirm_security
 */
final class PayFastSignature
{
    /**
     * @param  array<string, string|null>  $data
     */
    public static function build(array $data, ?string $passphrase): string
    {
        $fields = [];
        foreach ($data as $key => $value) {
            if ($key === 'signature') {
                continue;
            }
            if ($value === null || $value === '') {
                continue;
            }
            $fields[$key] = $value;
        }
        ksort($fields);

        $pairs = [];
        foreach ($fields as $key => $value) {
            $pairs[] = $key.'='.urlencode(trim((string) $value));
        }
        $query = implode('&', $pairs);
        if ($passphrase !== null && $passphrase !== '') {
            $query .= '&passphrase='.urlencode(trim($passphrase));
        }

        return md5($query);
    }

    /**
     * @param  array<string, mixed>  $posted
     */
    public static function verifyPosted(array $posted, ?string $passphrase): bool
    {
        $received = $posted['signature'] ?? null;
        if (! is_string($received) || $received === '') {
            return false;
        }

        /** @var array<string, string|null> $stringy */
        $stringy = [];
        foreach ($posted as $key => $value) {
            if (! is_string($key)) {
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $stringy[$key] = $value === null ? null : (string) $value;
            }
        }

        return hash_equals(self::build($stringy, $passphrase), $received);
    }
}
