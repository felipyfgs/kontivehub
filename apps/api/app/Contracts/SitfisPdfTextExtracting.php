<?php

namespace App\Contracts;

interface SitfisPdfTextExtracting
{
    /**
     * Extrai texto somente em memória; implementações devem falhar quando os
     * limites forem excedidos, sem persistir ou registrar o conteúdo integral.
     */
    public function extract(string $pdfBytes, int $maxTextBytes): string;
}
