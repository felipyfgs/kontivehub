<?php

namespace Tests\Unit\Fiscal\SimplesMei;

use App\DTO\Fiscal\SimplesMei\DasGuideDto;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DasGuideDtoTest extends TestCase
{
    #[Test]
    public function maps_official_gerar_das_fields_numero_documento_and_total(): void
    {
        $dto = DasGuideDto::fromIntegraBody([
            'numeroDocumento' => '07202619183811980',
            'total' => 150.5,
            'periodoApuracao' => '202606',
            'dataVencimento' => '2026-07-20',
        ], '2026-06');

        $this->assertSame('07202619183811980', $dto->documentNumber);
        $this->assertSame(150.5, $dto->amount);
        $this->assertSame('202606', $dto->competence);
        $this->assertSame('2026-07-20', $dto->dueDate);
        $this->assertSame('07202619183811980', $dto->toNormalized()['document_number']);
        $this->assertSame(150.5, $dto->toNormalized()['amount']);
    }

    #[Test]
    public function unwraps_dados_envelope_and_falls_back_to_principal(): void
    {
        $dto = DasGuideDto::fromIntegraBody([
            'status' => 200,
            'dados' => [
                'numeroDocumento' => '07202619183811981',
                'principal' => 80,
            ],
        ]);

        $this->assertSame('07202619183811981', $dto->documentNumber);
        $this->assertSame(80.0, $dto->amount);
    }

    #[Test]
    public function keeps_english_aliases(): void
    {
        $dto = DasGuideDto::fromIntegraBody([
            'document_number' => '07202619183811982',
            'amount' => 12.34,
            'competence' => '2026-05',
        ]);

        $this->assertSame('07202619183811982', $dto->documentNumber);
        $this->assertSame(12.34, $dto->amount);
        $this->assertSame('2026-05', $dto->competence);
    }
}
