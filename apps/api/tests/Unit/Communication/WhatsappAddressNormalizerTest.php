<?php

namespace Tests\Unit\Communication;

use App\Services\Communication\WhatsappAddressNormalizer;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WhatsappAddressNormalizerTest extends TestCase
{
    #[DataProvider('validAddresses')]
    public function test_normalizes_addresses_to_e164(string $input, string $expected): void
    {
        $this->assertSame($expected, app(WhatsappAddressNormalizer::class)->normalize($input));
    }

    /** @return array<string, array{string, string}> */
    public static function validAddresses(): array
    {
        return [
            'br local' => ['(11) 99999-1234', '+5511999991234'],
            'explicit plus' => ['+55 11 99999-1234', '+5511999991234'],
            'international prefix' => ['0055 11 99999-1234', '+5511999991234'],
            'whatsmeow jid' => ['5511999991234@s.whatsapp.net', '+5511999991234'],
            'opaque lid' => ['lid:987654321', 'lid:987654321'],
            'whatsmeow lid' => ['987654321@lid', 'lid:987654321'],
        ];
    }

    public function test_rejects_an_address_outside_e164_limits(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(WhatsappAddressNormalizer::class)->normalize('123');
    }

    #[DataProvider('forbiddenScopes')]
    public function test_rejects_non_one_to_one_jid_servers(string $address): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(WhatsappAddressNormalizer::class)->normalize($address);
    }

    /** @return array<string, array{string}> */
    public static function forbiddenScopes(): array
    {
        return [
            'group' => ['120363000000000000@g.us'],
            'newsletter' => ['120363000000000001@newsletter'],
            'broadcast' => ['status@broadcast'],
            'device jid' => ['5511999991234:2@s.whatsapp.net'],
            'unknown server' => ['5511999991234@example.invalid'],
        ];
    }
}
