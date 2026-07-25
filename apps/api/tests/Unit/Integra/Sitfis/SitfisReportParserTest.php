<?php

namespace Tests\Unit\Integra\Sitfis;

use App\Contracts\SitfisPdfTextExtracting;
use App\Enums\FiscalSituation;
use App\Services\Integra\Sitfis\SitfisReportParser;
use App\Services\Integra\Sitfis\SmalotSitfisPdfTextExtractor;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class SitfisReportParserTest extends TestCase
{
    public function test_known_layout_with_pendencias_is_pending_not_negative_certificate(): void
    {
        $parser = new SitfisReportParser;
        $result = $parser->parse([
            'situacao' => 'PENDENTE',
            'pendencias' => [
                [
                    'codigo' => 'RFB-01',
                    'descricao' => 'Débito em aberto',
                    'orgao' => 'RFB',
                ],
            ],
            'dataConsulta' => '2026-07-01',
        ]);

        $this->assertTrue($result->layoutRecognized);
        $this->assertSame(FiscalSituation::Pending, $result->situation);
        $this->assertFalse($result->claimsNegativeCertificate);
        $this->assertFalse((bool) ($result->normalized['is_negative_certificate'] ?? true));
        $this->assertNotEmpty($result->findings);
    }

    public function test_empty_layout_never_claims_negative_certificate(): void
    {
        $parser = new SitfisReportParser;
        $result = $parser->parse([]);

        $this->assertFalse($result->layoutRecognized);
        $this->assertSame(FiscalSituation::Attention, $result->situation);
        $this->assertFalse($result->claimsNegativeCertificate);
        $this->assertFalse((bool) ($result->normalized['is_negative_certificate'] ?? true));
    }

    public function test_unknown_free_text_never_claims_negative_certificate(): void
    {
        $parser = new SitfisReportParser;
        $result = $parser->parse('texto livre não estruturado do relatório');

        $this->assertFalse($result->layoutRecognized);
        $this->assertSame(FiscalSituation::Attention, $result->situation);
        $this->assertFalse($result->claimsNegativeCertificate);
    }

    public function test_official_pdf_bytes_are_recognized_without_negative_certificate(): void
    {
        $parser = new SitfisReportParser;
        $result = $parser->parse("%PDF-1.4\n%fake pdf body for sitfis");

        $this->assertFalse($result->layoutRecognized);
        $this->assertFalse($result->contractChanged);
        $this->assertSame(FiscalSituation::Attention, $result->situation);
        $this->assertFalse($result->claimsNegativeCertificate);
        $this->assertSame('pdf', $result->normalized['report_format'] ?? null);
        $this->assertSame('SITFIS_PDF_INCONCLUSIVE', $result->findings[0]['code'] ?? null);
    }

    public function test_pdf_with_pending_sections_is_pending_even_when_pgfn_is_clear(): void
    {
        $parser = new SitfisReportParser($this->extractorReturning(<<<'TEXT'
Pendência - Débito (SIEF) ______________________________________
Pendência - Omissão de DCTFWeb* _______________________________
Não foram detectadas pendências/exigibilidades suspensas para esse contribuinte nos controles da Procuradoria-Geral da Fazenda Nacional.
TEXT));

        $result = $parser->parse("%PDF-1.4\nfixture");

        $this->assertSame(FiscalSituation::Pending, $result->situation);
        $this->assertSame(['Débito (SIEF)', 'Omissão de DCTFWeb*'], $result->normalized['recognized_sections']);
        $this->assertCount(2, $result->findings);
        $this->assertTrue($result->findings[0]['creates_pending']);
        $this->assertFalse($result->claimsNegativeCertificate);
        $this->assertFalse($result->normalized['is_negative_certificate']);
    }

    public function test_pdf_with_joint_rfb_pgfn_no_pending_statement_is_up_to_date_not_certificate(): void
    {
        $parser = new SitfisReportParser($this->extractorReturning(
            'Não foram detectadas pendências/exigibilidades suspensas nos controles da Receita Federal e da Procuradoria-Geral da Fazenda Nacional.'
        ));

        $result = $parser->parse("%PDF-1.4\nfixture");

        $this->assertSame(FiscalSituation::UpToDate, $result->situation);
        $this->assertSame('NO_PENDING_RFB_PGFN', $result->normalized['parser_conclusion']);
        $this->assertSame([], $result->findings);
        $this->assertFalse($result->claimsNegativeCertificate);
        $this->assertFalse($result->normalized['is_negative_certificate']);
    }

    public function test_pdf_with_only_pgfn_no_pending_statement_remains_attention(): void
    {
        $parser = new SitfisReportParser($this->extractorReturning(
            'Não foram detectadas pendências/exigibilidades suspensas para esse contribuinte nos controles da Procuradoria-Geral da Fazenda Nacional.'
        ));

        $result = $parser->parse("%PDF-1.4\nfixture");

        $this->assertSame(FiscalSituation::Attention, $result->situation);
        $this->assertSame('INCONCLUSIVE', $result->normalized['parser_conclusion']);
    }

    public function test_pdf_extraction_failure_remains_attention(): void
    {
        $extractor = new class implements SitfisPdfTextExtracting
        {
            public function extract(string $pdfBytes, int $maxTextBytes): string
            {
                throw new RuntimeException('fixture failure');
            }
        };

        $result = (new SitfisReportParser($extractor))->parse("%PDF-1.4\nfixture");

        $this->assertSame(FiscalSituation::Attention, $result->situation);
        $this->assertSame('SITFIS_PDF_INCONCLUSIVE', $result->findings[0]['code']);
    }

    public function test_structured_report_does_not_invoke_pdf_extractor(): void
    {
        $extractor = new class implements SitfisPdfTextExtracting
        {
            public bool $called = false;

            public function extract(string $pdfBytes, int $maxTextBytes): string
            {
                $this->called = true;

                return '';
            }
        };
        $parser = new SitfisReportParser($extractor);

        $result = $parser->parse([
            'situacao' => 'PENDENTE',
            'pendencias' => [['codigo' => 'RFB-01', 'descricao' => 'Débito em aberto']],
        ]);

        $this->assertSame(FiscalSituation::Pending, $result->situation);
        $this->assertFalse($extractor->called);
    }

    public function test_smalot_extractor_reads_sanitized_text_pdf_in_memory(): void
    {
        $text = (new SmalotSitfisPdfTextExtractor)->extract(
            $this->sanitizedPdf('Relatorio SITFIS sanitizado'),
            10_000,
        );

        $this->assertStringContainsString('Relatorio SITFIS sanitizado', $text);
    }

    public function test_known_layout_without_items_is_unknown_not_up_to_date(): void
    {
        $parser = new SitfisReportParser;
        $result = $parser->parse([
            'situacao' => 'REGULAR',
            'pendencias' => [],
            'cabecalho' => ['contribuinte' => 'ACME'],
            'dataConsulta' => '2026-07-01',
        ]);

        $this->assertTrue($result->layoutRecognized);
        $this->assertSame(FiscalSituation::Unknown, $result->situation);
        $this->assertFalse($result->claimsNegativeCertificate);
        $this->assertFalse((bool) ($result->normalized['is_negative_certificate'] ?? true));
    }

    private function extractorReturning(string $text): SitfisPdfTextExtracting
    {
        return new class($text) implements SitfisPdfTextExtracting
        {
            public function __construct(private readonly string $text) {}

            public function extract(string $pdfBytes, int $maxTextBytes): string
            {
                return $this->text;
            }
        };
    }

    private function sanitizedPdf(string $text): string
    {
        $stream = "BT /F1 12 Tf 72 720 Td ({$text}) Tj ET";
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
            4 => '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream",
            5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [0 => 0];
        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($pdf);
            $pdf .= "{$number} 0 obj\n{$body}\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 6\n0000000000 65535 f \n";
        for ($number = 1; $number <= 5; $number++) {
            $pdf .= sprintf('%010d 00000 n ', $offsets[$number])."\n";
        }

        return $pdf."trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
    }
}
