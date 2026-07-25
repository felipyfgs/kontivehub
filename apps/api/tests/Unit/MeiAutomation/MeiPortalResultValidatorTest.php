<?php

namespace Tests\Unit\MeiAutomation;

use App\Services\MeiAutomation\MeiPortalResultValidator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MeiPortalResultValidatorTest extends TestCase
{
    #[Test]
    public function it_keeps_only_structured_das_metadata_and_not_the_barcode(): void
    {
        $result = (new MeiPortalResultValidator)->validate('pgmei.gerardascodbarra', [
            'competencies' => ['2026-01'],
            'submitted' => true,
            'barcode' => str_repeat('8', 48),
            'parser_version' => 'pgmei-1',
            'portal_version' => 'fixture-1',
            'unexpected' => 'discarded',
        ]);

        self::assertIsArray($result);
        self::assertTrue($result['barcode_available']);
        self::assertArrayNotHasKey('barcode', $result);
        self::assertArrayNotHasKey('unexpected', $result);
    }

    #[Test]
    public function it_rejects_full_dasn_coverage_without_a_valid_artifact_reference(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new MeiPortalResultValidator)->validate('dasnsimei.consultimadecrec', [
            'coverage' => 'FULL',
            'parser_version' => 'dasnsimei-1',
            'portal_version' => 'fixture-1',
            'declarations' => [[
                'calendar_year' => 2024,
                'status' => 'Transmitida',
                'transmitted_at' => '2025-05-15',
                'coverage' => 'FULL',
                'receipt_available' => true,
                'receipt_artifact_id' => 'not-a-uuid',
            ]],
        ]);
    }

    #[Test]
    public function it_preserves_structured_public_dasn_status_without_promoting_coverage(): void
    {
        $result = (new MeiPortalResultValidator)->validate('dasnsimei.consultimadecrec', [
            'coverage' => 'SUMMARY',
            'parser_version' => 'dasnsimei-1',
            'portal_version' => 'live-unversioned',
            'declarations' => [[
                'calendar_year' => 2025,
                'status' => 'Não apresentada',
                'transmitted_at' => null,
                'declaration_type' => 'Original',
                'special_situation' => 'Extinção',
                'special_situation_date' => '2026-05-20',
                'pending' => true,
                'coverage' => 'SUMMARY',
                'receipt_available' => false,
                'receipt_artifact_id' => null,
            ]],
        ]);

        self::assertIsArray($result);
        self::assertSame('SUMMARY', $result['coverage']);
        self::assertTrue($result['declarations'][0]['pending']);
        self::assertSame('Original', $result['declarations'][0]['declaration_type']);
        self::assertSame('Extinção', $result['declarations'][0]['special_situation']);
        self::assertSame('2026-05-20', $result['declarations'][0]['special_situation_date']);
    }
}
