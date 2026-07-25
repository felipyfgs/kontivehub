<?php

namespace Tests\Unit\Fiscal\SimplesMei;

use App\Enums\PgdasdRbt12Status;
use App\Services\Fiscal\SimplesMei\Pgdasd\PgdasdRbt12Parser;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class PgdasdRbt12ParserTest extends TestCase
{
    private PgdasdRbt12Parser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new PgdasdRbt12Parser;
    }

    public function test_parses_single_line_rbt12(): void
    {
        $text = "Período de Apuração: 05/2026\n"
            ."RBT12 Mercado Interno 10.000,00 Mercado Externo 0,00 Total 10.000,00\n";

        $parsed = $this->parser->parse($text, '202605');

        $this->assertSame(PgdasdRbt12Status::Parsed, $parsed['status']);
        $this->assertSame(1_000_000, $parsed['total_cents']);
        $this->assertSame(1_000_000, $parsed['internal_market_cents']);
        $this->assertSame(0, $parsed['external_market_cents']);
        $this->assertSame('pgdasd-rbt12-v4', $parsed['parser_version']);
    }

    public function test_parses_official_cons_extrato_layout_with_doze_meses(): void
    {
        $text = <<<'TXT'
Período de Apuração (PA): 05/2026
2.1 Discriminativo de Receitas
Total de Receitas Brutas (R$)                                Mercado Interno      Mercado Externo        Total
Receita Bruta do PA (RPA) - Competência                               10.325,35               0,00          10.325,35
Receita bruta acumulada nos doze meses anteriores ao PA            305.379,40                 0,00         305.379,40
(RBT12)
Receita bruta acumulada nos doze meses anteriores ao PA
proporcionalizada (RBT12p)
Receita bruta acumulada no ano-calendário corrente (RBA)           141.380,49                 0,00         141.380,49
TXT;

        $parsed = $this->parser->parse($text, '202605');

        $this->assertSame(PgdasdRbt12Status::Parsed, $parsed['status']);
        $this->assertSame(30_537_940, $parsed['total_cents']);
        $this->assertSame(30_537_940, $parsed['internal_market_cents']);
        $this->assertSame(0, $parsed['external_market_cents']);
        $this->assertSame(1_032_535, $parsed['rpa_cents']);
        $this->assertSame('pgdasd-rbt12-v4', $parsed['parser_version']);
    }

    public function test_parses_declaration_layout_with_values_before_ao_pa_rbt12_continuation(): void
    {
        // Declaração PGDAS-D (sem DAS): rótulo na 1ª linha + valores; continuação "ao PA (RBT12)".
        $text = <<<'TXT'
Período de Apuração (PA): 06/2026
Total de Receitas Brutas (R$)                                Mercado Interno      Mercado Externo        Total
Receita Bruta do PA (RPA) - Competência                               12.500,00               0,00          12.500,00
Receita bruta acumulada nos doze meses anteriores         149.015,00               0,00         149.015,00
ao PA (RBT12)
Receita bruta acumulada nos doze meses anteriores ao PA
proporcionalizada (RBT12p)
TXT;

        $parsed = $this->parser->parse($text, '202606');

        $this->assertSame(PgdasdRbt12Status::Parsed, $parsed['status']);
        $this->assertSame(14_901_500, $parsed['total_cents']);
        $this->assertSame(14_901_500, $parsed['internal_market_cents']);
        $this->assertSame(0, $parsed['external_market_cents']);
        $this->assertSame('pgdasd-rbt12-v4', $parsed['parser_version']);
    }

    #[DataProvider('unavailableProvider')]
    public function test_fail_closed_when_unavailable(string $text, string $pa, PgdasdRbt12Status $status, string $reason): void
    {
        $parsed = $this->parser->parse($text, $pa);

        $this->assertSame($status, $parsed['status']);
        $this->assertNull($parsed['total_cents']);
        $this->assertSame($reason, $parsed['reason']);
    }

    /**
     * @return array<string, array{0:string,1:string,2:PgdasdRbt12Status,3:string}>
     */
    public static function unavailableProvider(): array
    {
        return [
            'empty' => ['', '202605', PgdasdRbt12Status::NotFound, 'EMPTY_TEXT'],
            'bad_period' => ["RBT12 Total 10,00\n", '2026', PgdasdRbt12Status::Failed, 'INVALID_EXPECTED_PERIOD'],
            'period_mismatch' => [
                "Período 04/2026\nRBT12 Total 10.000,00\n",
                '202605',
                PgdasdRbt12Status::Ambiguous,
                'PERIOD_MISMATCH',
            ],
            'not_found' => [
                "Período 05/2026\nsem valores de receita\n",
                '202605',
                PgdasdRbt12Status::NotFound,
                'EXACT_RBT12_VALUE_NOT_FOUND',
            ],
        ];
    }
}
