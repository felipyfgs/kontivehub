<?php

namespace Tests\Unit\Communication;

use App\Services\Communication\Contact\SharedVCardParser;
use Tests\TestCase;

class SharedVCardParserTest extends TestCase
{
    public function test_unescape_decodes_supported_sequences_without_reprocessing_results(): void
    {
        $parser = app(SharedVCardParser::class);

        $escapedNewline = $parser->parse("BEGIN:VCARD\r\nFN:Linha\\nNova\r\nEND:VCARD");
        $literalBackslashN = $parser->parse("BEGIN:VCARD\r\nFN:Literal\\\\nNome\r\nEND:VCARD");

        $this->assertSame("Linha\nNova", $escapedNewline['display_name']);
        $this->assertSame('Literal\\nNome', $literalBackslashN['display_name']);
    }

    public function test_structured_name_preserves_escaped_semicolons_and_vcard_order(): void
    {
        $parsed = app(SharedVCardParser::class)->parse(
            "BEGIN:VCARD\r\nN:Sobrenome;Nome\\;Composto;Adicional;Dra.;Neta\r\nEND:VCARD",
        );

        $this->assertSame('Dra. Nome;Composto Adicional Sobrenome Neta', $parsed['display_name']);
    }

    public function test_malformed_or_multiple_cards_keep_only_the_safe_fallback(): void
    {
        $parser = app(SharedVCardParser::class);
        $missingEnd = $parser->parse(
            "BEGIN:VCARD\r\nFN:Incompleto\r\nTEL:+5511999990001",
            'Fallback seguro',
        );
        $multiple = $parser->parse(
            "BEGIN:VCARD\r\nFN:Um\r\nEND:VCARD\r\nBEGIN:VCARD\r\nFN:Dois\r\nEND:VCARD",
            'Fallback seguro',
        );

        $this->assertSame(['display_name' => 'Fallback seguro', 'phones' => []], $missingEnd);
        $this->assertSame(['display_name' => 'Fallback seguro', 'phones' => []], $multiple);
    }
}
