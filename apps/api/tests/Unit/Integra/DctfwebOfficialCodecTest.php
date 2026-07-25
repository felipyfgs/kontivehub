<?php

namespace Tests\Unit\Integra;

use App\Services\Integra\Dctfweb\DctfwebOfficialCodec;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class DctfwebOfficialCodecTest extends TestCase
{
    private DctfwebOfficialCodec $codec;

    protected function setUp(): void
    {
        parent::setUp();
        $this->codec = new DctfwebOfficialCodec;
    }

    public function test_builds_the_official_monthly_payload(): void
    {
        $this->assertSame([
            'categoria' => '40',
            'anoPA' => '2027',
            'mesPA' => '11',
        ], $this->codec->periodPayload('2027-11'));
    }

    public function test_decodes_pdf_and_xml_from_documented_dados_fields(): void
    {
        $pdf = '%PDF-1.4 fixture %%EOF';
        $xml = '<?xml version="1.0"?><Termo />';

        $this->assertSame($pdf, $this->codec->decodePdf([
            'PDFByteArrayBase64' => base64_encode($pdf),
        ]));
        $this->assertSame($xml, $this->codec->decodeXml([
            'XMLStringBase64' => base64_encode($xml),
        ]));
    }

    public function test_rejects_non_pdf_document(): void
    {
        $this->expectException(RuntimeException::class);
        $this->codec->decodePdf(['PDFByteArrayBase64' => base64_encode('not a PDF')]);
    }

    public function test_rejects_missing_document_field(): void
    {
        $this->expectException(RuntimeException::class);
        $this->codec->decodePdf(['status' => 200]);
    }

    public function test_redacts_base64_before_projection_metadata(): void
    {
        $sanitized = $this->codec->sanitize([
            'PDFByteArrayBase64' => 'secret-document',
            'numeroDocumento' => '123',
        ]);

        $this->assertSame('123', $sanitized['numeroDocumento']);
        $this->assertTrue($sanitized['PDFByteArrayBase64']['redacted']);
        $this->assertSame(15, $sanitized['PDFByteArrayBase64']['byte_size']);
    }
}
