<?php

declare(strict_types=1);

namespace Tests\Unit\Esocial;

use App\DTO\Esocial\EsocialBxDownloadResult;
use App\DTO\Esocial\EsocialBxHttpResponse;
use App\DTO\Esocial\EsocialBxIdentifier;
use App\DTO\Esocial\EsocialBxIdentifiersResult;
use App\DTO\Esocial\EsocialBxReadiness;
use App\DTO\Esocial\EsocialEventDto;
use App\Enums\EsocialBxFailureClass;
use App\Enums\EsocialEventCode;
use App\Exceptions\EsocialBxException;
use InvalidArgumentException;
use Tests\TestCase;

class EsocialBxDtoTest extends TestCase
{
    public function test_identifier_and_result_expose_only_counts_and_hashes(): void
    {
        $identifier = new EsocialBxIdentifier('ID12345678901234567890', '1.2.000000000000001');
        $result = new EsocialBxIdentifiersResult([$identifier], false, '201');

        $this->assertSame(['ID12345678901234567890'], $result->ids());
        $this->assertSame('1.2.000000000000001', $result->receiptsById()[$identifier->id]);
        $serialized = json_encode([
            $identifier->toSanitizedArray(),
            $result->toSanitizedArray(),
        ], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($identifier->id, $serialized);
        $this->assertStringNotContainsString('1.2.000000000000001', $serialized);
    }

    public function test_download_and_http_response_never_serialize_xml_or_unlisted_metadata(): void
    {
        $xml = '<eSocial><evtFGTS><ideEvento><perApur>2026-06</perApur></ideEvento></evtFGTS></eSocial>';
        $event = new EsocialEventDto(
            EsocialEventCode::S5013,
            '2026-06',
            $xml,
            establishmentCnpj: '48123272000105',
            metadata: ['source' => 'ESOCIAL_BX_OFFICIAL', 'password' => 'never-serialize'],
        );
        $download = new EsocialBxDownloadResult([$event], false, '201');
        $http = new EsocialBxHttpResponse(200, $xml);
        $serialized = json_encode([
            $event->toSanitizedArray(),
            $download->toSanitizedArray(),
            $http->toSanitizedArray(),
        ], JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString($xml, $serialized);
        $this->assertStringNotContainsString('never-serialize', $serialized);
        $this->assertStringNotContainsString('48123272000105', $serialized);
        $this->assertStringContainsString('000105', $serialized);
    }

    public function test_readiness_validates_quota_and_only_emits_fingerprint_suffix(): void
    {
        $readiness = new EsocialBxReadiness(
            ready: false,
            driver: 'official_bx',
            environment: 'restricted',
            blockers: [['code' => 'ESOCIAL_BX_BLOCKED_WINDOW', 'message' => 'Janela bloqueada.']],
            dailyLimit: 10,
            locallyConsumed: 3,
            locallyRemaining: 7,
            credentialFingerprint: str_repeat('a', 64),
        );

        $encoded = json_encode($readiness->toArray(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString(str_repeat('a', 64), $encoded);
        $this->assertStringContainsString(str_repeat('a', 12), $encoded);

        $this->expectException(InvalidArgumentException::class);
        new EsocialBxReadiness(true, 'official_bx', 'restricted', [], 10, 4, 7);
    }

    public function test_failures_have_stable_classification_and_sanitized_shape(): void
    {
        $retryable = new EsocialBxException('ESOCIAL_BX_TIMEOUT', 'Mensagem interna.', retryable: true);
        $blocked = new EsocialBxException(
            'ESOCIAL_BX_QUOTA_EXHAUSTED',
            'Mensagem interna.',
            blocked: true,
            officialCode: '405',
        );
        $permanent = new EsocialBxException('ESOCIAL_BX_EVENT_TYPE_MISMATCH', 'Mensagem interna.');

        $this->assertSame(EsocialBxFailureClass::Retryable, $retryable->classification());
        $this->assertSame(EsocialBxFailureClass::Blocked, $blocked->classification());
        $this->assertSame(EsocialBxFailureClass::Permanent, $permanent->classification());
        $this->assertArrayNotHasKey('message', $blocked->toSanitizedArray());
        $this->assertSame('405', $blocked->toSanitizedArray()['official_code']);
    }

    public function test_invalid_identifier_and_failure_codes_are_rejected(): void
    {
        try {
            new EsocialBxIdentifier('unsafe');
            $this->fail('Identificador inválido deveria falhar.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }

        $this->expectException(InvalidArgumentException::class);
        new EsocialBxException('INVALID', 'Mensagem.');
    }
}
