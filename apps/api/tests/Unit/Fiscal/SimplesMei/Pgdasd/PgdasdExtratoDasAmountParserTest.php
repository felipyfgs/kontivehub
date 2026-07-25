<?php

namespace Tests\Unit\Fiscal\SimplesMei\Pgdasd;

use App\Services\Fiscal\SimplesMei\Pgdasd\PgdasdExtratoDasAmountParser;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PgdasdExtratoDasAmountParserTest extends TestCase
{
    private PgdasdExtratoDasAmountParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new PgdasdExtratoDasAmountParser;
    }

    #[Test]
    public function parses_section_6_total_when_das_number_matches(): void
    {
        $text = <<<'TXT'
5) Outras informações
6) Informações sobre DAS Gerado na apuração: 26461528202601001
                                        Data de Vencimento:
Número: 07202604328595614                                           Data limite para acolhimento: 20/02/2026
                                        20/02/2026
IRPJ                  16,80 CSLL                    14,70 COFINS              53,84 PIS/PASEP                11,68
INSS/CPP             182,28 ICMS                     0,00 IPI                  0,00 ISS                     140,70
Principal            420,00 Multa                    0,00 Juros                0,00 Total                   420,00
6.1) Discriminação dos Valores Calculados no DAS Gerado
TXT;

        $parsed = $this->parser->parse($text, '07202604328595614');

        $this->assertTrue($parsed['ok']);
        $this->assertSame(42000, $parsed['amount_cents']);
        $this->assertSame('07202604328595614', $parsed['das_number']);
        $this->assertSame(PgdasdExtratoDasAmountParser::VERSION, $parsed['parser_version']);
    }

    #[Test]
    public function uses_total_not_principal_when_they_differ(): void
    {
        $text = <<<'TXT'
6) Informações sobre DAS Gerado na apuração: x
Número: 07202604468583139
Principal            435,23 Multa                   30,00 Juros               11,69 Total                   476,92
6.1) Discriminação
TXT;

        $parsed = $this->parser->parse($text, '07202604468583139');

        $this->assertTrue($parsed['ok']);
        $this->assertSame(47692, $parsed['amount_cents']);
    }

    #[Test]
    public function parses_brazilian_thousands_separator(): void
    {
        $text = <<<'TXT'
6) Informações sobre DAS Gerado na apuração: x
Número: 07202619183811980
Principal          1.100,00 Multa                    0,00 Juros                0,00 Total                 1.124,35
6.1) Discriminação
TXT;

        $parsed = $this->parser->parse($text, '07202619183811980');

        $this->assertTrue($parsed['ok']);
        $this->assertSame(112435, $parsed['amount_cents']);
    }

    #[Test]
    public function fails_when_das_number_mismatches(): void
    {
        $text = <<<'TXT'
6) Informações sobre DAS Gerado na apuração: x
Número: 07202604328595614
Principal            420,00 Multa                    0,00 Juros                0,00 Total                   420,00
6.1) Discriminação
TXT;

        $parsed = $this->parser->parse($text, '07202600000000000');

        $this->assertFalse($parsed['ok']);
        $this->assertSame('DAS_NUMBER_MISMATCH', $parsed['reason']);
        $this->assertNull($parsed['amount_cents']);
    }

    #[Test]
    public function fails_when_section_missing(): void
    {
        $parsed = $this->parser->parse("sem secao seis\nTotal 10,00", '07202604328595614');

        $this->assertFalse($parsed['ok']);
        $this->assertSame('SECTION_6_NOT_FOUND', $parsed['reason']);
    }
}
