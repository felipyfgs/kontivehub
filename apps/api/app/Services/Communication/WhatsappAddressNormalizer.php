<?php

namespace App\Services\Communication;

use InvalidArgumentException;

final class WhatsappAddressNormalizer
{
    public function normalize(string $address, string $defaultCountryCode = '55'): string
    {
        $value = trim($address);
        if (preg_match('/^lid:([1-9][0-9]{0,19})$/', $value, $lid) === 1) {
            return 'lid:'.$lid[1];
        }
        if (str_contains($value, '@')) {
            if (preg_match('/^([1-9][0-9]{7,14})@s\.whatsapp\.net$/', $value, $phone) === 1) {
                return '+'.$phone[1];
            }
            if (preg_match('/^([1-9][0-9]{0,19})@lid$/', $value, $lid) === 1) {
                return 'lid:'.$lid[1];
            }
            throw new InvalidArgumentException('Endereço WhatsApp fora do escopo 1:1.');
        }
        $hasInternationalPrefix = str_starts_with($value, '+') || str_starts_with($value, '00');
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        if (str_starts_with($value, '00')) {
            $digits = substr($digits, 2);
        }

        if (! $hasInternationalPrefix && in_array(strlen($digits), [10, 11], true)) {
            $digits = $defaultCountryCode.$digits;
        }

        if (! preg_match('/^[1-9][0-9]{7,14}$/', $digits)) {
            throw new InvalidArgumentException('Endereço WhatsApp inválido.');
        }

        return '+'.$digits;
    }
}
