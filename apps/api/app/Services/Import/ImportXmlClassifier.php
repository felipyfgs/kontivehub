<?php

namespace App\Services\Import;

use DOMDocument;

/**
 * Classificação estrita de artefatos de import (notas/eventos vs inválidos).
 */
final class ImportXmlClassifier
{
    public function __construct(
        private readonly SecureXmlLoader $loader = new SecureXmlLoader,
    ) {}

    /**
     * @return array{
     *   kind: 'procNFe'|'procEventoNFe'|'procCTe'|'procEventoCTe'|'NFe_bare'|'resNFe'|'resCTe'|'invalid'|'unsupported',
     *   model: ?string,
     *   message?: string,
     *   doc?: DOMDocument
     * }
     */
    public function classify(string $bytes): array
    {
        $trim = ltrim($bytes);
        // PDF / HTML / binary heuristics
        if (str_starts_with($trim, '%PDF')) {
            return ['kind' => 'unsupported', 'model' => null, 'message' => 'PDF/DANFE não é XML fiscal de guarda.'];
        }
        if (preg_match('/^<!DOCTYPE\s+html/i', $trim) || preg_match('/^<html[\s>]/i', $trim)) {
            return ['kind' => 'unsupported', 'model' => null, 'message' => 'HTML não é XML fiscal de guarda.'];
        }

        try {
            $doc = $this->loader->load($bytes);
        } catch (\Throwable $e) {
            return ['kind' => 'invalid', 'model' => null, 'message' => $e->getMessage()];
        }

        $root = $doc->documentElement;
        $local = $root?->localName ?: $root?->tagName ?: '';
        $local = preg_replace('/^.*:/', '', (string) $local) ?? (string) $local;

        // CT-e (modelo 57) — por conteúdo, não por nome de arquivo
        if (in_array($local, ['cteProc', 'procCTe'], true) || $this->has($doc, 'cteProc') || $this->has($doc, 'protCTe')) {
            $model = $this->extractCteModel($doc, $bytes);

            return ['kind' => 'procCTe', 'model' => $model, 'doc' => $doc];
        }

        if (in_array($local, ['procEventoCTe', 'retEventoCTe'], true)
            || $this->has($doc, 'procEventoCTe')
            || ($this->has($doc, 'eventoCTe') && $this->has($doc, 'chCTe'))) {
            return ['kind' => 'procEventoCTe', 'model' => '57', 'doc' => $doc];
        }

        if ($local === 'resCTe' || $this->has($doc, 'resCTe')) {
            return ['kind' => 'resCTe', 'model' => '57', 'message' => 'resCTe não é XML completo.', 'doc' => $doc];
        }

        if (in_array($local, ['nfeProc', 'procNFe'], true) || $this->has($doc, 'nfeProc') || $this->has($doc, 'protNFe')) {
            $model = $this->extractModel($doc, $bytes);

            return ['kind' => 'procNFe', 'model' => $model, 'doc' => $doc];
        }

        if (in_array($local, ['procEventoNFe', 'retEvento'], true) || $this->has($doc, 'procEventoNFe') || $this->has($doc, 'evento')) {
            // Não confundir evento CT-e (já tratado) com NF-e
            if ($this->has($doc, 'chCTe') && ! $this->has($doc, 'chNFe')) {
                return ['kind' => 'procEventoCTe', 'model' => '57', 'doc' => $doc];
            }
            $model = $this->extractModelFromEvent($doc);

            return ['kind' => 'procEventoNFe', 'model' => $model, 'doc' => $doc];
        }

        if ($local === 'NFe' || $this->has($doc, 'infNFe')) {
            // bare NFe without protocol
            if (! $this->has($doc, 'protNFe') && ! $this->has($doc, 'nfeProc')) {
                return [
                    'kind' => 'NFe_bare',
                    'model' => $this->extractModel($doc, $bytes),
                    'message' => 'NFe sem protocolo — não é documento de guarda.',
                    'doc' => $doc,
                ];
            }

            return ['kind' => 'procNFe', 'model' => $this->extractModel($doc, $bytes), 'doc' => $doc];
        }

        if ($local === 'resNFe' || $this->has($doc, 'resNFe')) {
            return ['kind' => 'resNFe', 'model' => $this->extractModel($doc, $bytes), 'message' => 'resNFe não é XML completo.', 'doc' => $doc];
        }

        return ['kind' => 'unsupported', 'model' => null, 'message' => 'XML não fiscal ou tipo não suportado no import.', 'doc' => $doc];
    }

    private function extractCteModel(DOMDocument $doc, string $bytes): ?string
    {
        $nodes = $doc->getElementsByTagName('mod');
        if ($nodes->length > 0) {
            $v = trim((string) $nodes->item(0)?->textContent);

            return $v !== '' ? $v : null;
        }
        if (preg_match('/Id\s*=\s*"CTe([A-Za-z0-9]{44})"/', $bytes, $m)
            || preg_match('/chCTe>([A-Za-z0-9]{44})</', $bytes, $m)) {
            return substr(strtoupper($m[1]), 20, 2);
        }

        return '57';
    }

    private function has(DOMDocument $doc, string $localName): bool
    {
        return $doc->getElementsByTagName($localName)->length > 0
            || $doc->getElementsByTagNameNS('*', $localName)->length > 0;
    }

    private function extractModel(DOMDocument $doc, string $bytes): ?string
    {
        $nodes = $doc->getElementsByTagName('mod');
        if ($nodes->length > 0) {
            $v = trim((string) $nodes->item(0)?->textContent);

            return $v !== '' ? $v : null;
        }
        // from access key position 21-22 (0-based 20)
        if (preg_match('/Id\s*=\s*"NFe([A-Za-z0-9]{44})"/', $bytes, $m)
            || preg_match('/chNFe>([A-Za-z0-9]{44})</', $bytes, $m)) {
            return substr(strtoupper($m[1]), 20, 2);
        }

        return null;
    }

    private function extractModelFromEvent(DOMDocument $doc): ?string
    {
        $nodes = $doc->getElementsByTagName('chNFe');
        if ($nodes->length > 0) {
            $ch = strtoupper(trim((string) $nodes->item(0)?->textContent));
            if (strlen($ch) >= 22) {
                return substr($ch, 20, 2);
            }
        }

        return null;
    }
}
