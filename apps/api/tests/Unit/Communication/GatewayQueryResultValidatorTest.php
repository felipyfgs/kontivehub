<?php

namespace Tests\Unit\Communication;

use App\Enums\Communication\GatewayQueryType;
use App\Services\Communication\Gateway\GatewayQueryResultValidator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class GatewayQueryResultValidatorTest extends TestCase
{
    public function test_contact_profiles_preserve_separate_local_store_fields(): void
    {
        app(GatewayQueryResultValidator::class)->assertValid(
            GatewayQueryType::ContactProfiles,
            ['profiles' => [[
                'user' => '+5511999991234',
                'found' => true,
                'address_book_first_name' => 'Maria',
                'address_book_full_name' => 'Maria Silva',
                'push_name' => 'Maria S.',
                'business_name' => 'Maria Contábil',
                'observed_at' => '2026-07-28T20:00:00Z',
                'event_id' => 'contact-profile-0001',
            ], [
                'user' => 'lid:123456789',
                'found' => false,
            ]]],
        );

        $this->addToAssertionCount(1);
    }

    #[DataProvider('invalidContactProfileResults')]
    public function test_contact_profiles_reject_ambiguous_or_unsafe_shapes(array $result): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(GatewayQueryResultValidator::class)->assertValid(
            GatewayQueryType::ContactProfiles,
            $result,
        );
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidContactProfileResults(): iterable
    {
        yield 'missing found' => [['profiles' => [['user' => '+5511999991234']]]];
        yield 'raw jid' => [['profiles' => [['user' => '5511999991234@s.whatsapp.net', 'found' => true]]]];
        yield 'conflated  name' => [['profiles' => [[
            'user' => '+5511999991234',
            'found' => true,
            'address_book_name' => 'Maria',
        ]]]];
        yield 'verified is not in contact store result' => [['profiles' => [[
            'user' => '+5511999991234',
            'found' => true,
            'verified_name' => 'Maria',
        ]]]];
        yield 'unknown field' => [['profiles' => [[
            'user' => '+5511999991234',
            'found' => true,
            'picture_url' => 'https://example.test/avatar',
        ]]]];
        yield 'zero lid' => [['profiles' => [['user' => 'lid:0', 'found' => false]]]];
        yield 'zero prefixed lid' => [['profiles' => [['user' => 'lid:0001', 'found' => false]]]];
        yield 'short e164' => [['profiles' => [['user' => '+5512345', 'found' => false]]]];
        yield 'nullable observed at' => [['profiles' => [[
            'user' => '+5511999991234',
            'found' => true,
            'observed_at' => null,
        ]]]];
    }

    #[DataProvider('invalidExistingQueryResults')]
    public function test_existing_query_results_remain_fail_closed(
        GatewayQueryType $type,
        array $result,
    ): void {
        $this->expectException(InvalidArgumentException::class);

        app(GatewayQueryResultValidator::class)->assertValid($type, $result);
    }

    /** @return iterable<string, array{GatewayQueryType, array<string, mixed>}> */
    public static function invalidExistingQueryResults(): iterable
    {
        yield 'qr link is not string' => [
            GatewayQueryType::ContactQrLink,
            ['contact_qr_link' => ['link' => []]],
        ];
        yield 'profile picture rejects extra root' => [
            GatewayQueryType::ProfilePicture,
            ['profile_picture' => null, 'extra' => 'unsafe'],
        ];
        yield 'user info status is not string' => [
            GatewayQueryType::UserInfo,
            ['user_info' => [['user' => '+5511999991234', 'status' => []]]],
        ];
        yield 'privacy value outside matrix' => [
            GatewayQueryType::PrivacySettings,
            ['settings' => [['name' => 'profile', 'value' => 'everyone']]],
        ];
    }
}
