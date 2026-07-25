<?php

namespace App\Services\Import;

use DOMDocument;
use RuntimeException;

/**
 * Carrega XML fiscal sem rede, filesystem, DTD, entidades externas ou XInclude.
 */
final class SecureXmlLoader
{
    public function load(string $bytes, int $maxDepth = 64, int $maxNodes = 200_000): DOMDocument
    {
        if ($bytes === '' || ! str_contains($bytes, '<')) {
            throw new RuntimeException('Conteúdo não é XML.');
        }

        $prev = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->resolveExternals = false;
        $doc->substituteEntities = false;
        $doc->validateOnParse = false;
        $doc->strictErrorChecking = false;

        $flags = LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT;
        if (defined('LIBXML_NOCDATA')) {
            $flags |= LIBXML_NOCDATA;
        }
        // PHP 8+: disable network already via NONET; DTD load off
        if (defined('LIBXML_DTDLOAD')) {
            // intentionally NOT setting DTDLOAD / DTDATTR / DTDVALID / XINCLUDE
        }
        if (defined('LIBXML_NOENT')) {
            // do not enable NOENT (would expand entities)
        }

        $ok = @$doc->loadXML($bytes, $flags);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if (! $ok || $doc->documentElement === null) {
            throw new RuntimeException('XML malformado ou rejeitado pelo parser seguro.');
        }

        // XInclude nodes
        if ($doc->getElementsByTagNameNS('http://www.w3.org/2001/XInclude', 'include')->length > 0
            || $doc->getElementsByTagName('xi:include')->length > 0) {
            throw new RuntimeException('XInclude não permitido.');
        }

        // DOCTYPE / DTD
        if ($doc->doctype !== null) {
            throw new RuntimeException('DTD/DOCTYPE não permitido.');
        }

        $this->assertDepthAndNodes($doc->documentElement, $maxDepth, $maxNodes);

        return $doc;
    }

    private function assertDepthAndNodes(\DOMNode $root, int $maxDepth, int $maxNodes): void
    {
        $nodes = 0;
        $walk = function (\DOMNode $node, int $depth) use (&$walk, &$nodes, $maxDepth, $maxNodes): void {
            $nodes++;
            if ($nodes > $maxNodes) {
                throw new RuntimeException('XML excede limite de nós.');
            }
            if ($depth > $maxDepth) {
                throw new RuntimeException('XML excede profundidade máxima.');
            }
            foreach ($node->childNodes ?: [] as $child) {
                if ($child->nodeType === XML_ELEMENT_NODE) {
                    $walk($child, $depth + 1);
                }
            }
        };
        $walk($root, 1);
    }
}
