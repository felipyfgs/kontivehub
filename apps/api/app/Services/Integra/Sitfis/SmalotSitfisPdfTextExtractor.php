<?php

namespace App\Services\Integra\Sitfis;

use App\Contracts\SitfisPdfTextExtracting;
use RuntimeException;
use Smalot\PdfParser\Parser;

final class SmalotSitfisPdfTextExtractor implements SitfisPdfTextExtracting
{
    public function extract(string $pdfBytes, int $maxTextBytes): string
    {
        $text = (new Parser)->parseContent($pdfBytes)->getText();

        if ($text === '') {
            throw new RuntimeException('PDF SITFIS sem texto extraível.');
        }

        if (strlen($text) > $maxTextBytes) {
            throw new RuntimeException('Texto do PDF SITFIS excede o limite de análise.');
        }

        return $text;
    }
}
