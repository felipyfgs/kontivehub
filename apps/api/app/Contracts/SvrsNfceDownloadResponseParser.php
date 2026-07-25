<?php

namespace App\Contracts;

use App\DTO\Outbound\SvrsNfceParseResult;

/**
 * Parser versionado do wrapper HTML/JS do DownloadXMLDFe.
 * Sem eval, engine JS ou stripcslashes genérico.
 */
interface SvrsNfceDownloadResponseParser
{
    public function parserVersion(): string;

    /**
     * Valida marcadores/formulário esperados na resposta GET.
     */
    public function parseFormPage(string $html): SvrsNfceParseResult;

    /**
     * Extrai o único literal do Blob/download oficial na resposta POST.
     */
    public function parseDownloadPage(string $html): SvrsNfceParseResult;
}
