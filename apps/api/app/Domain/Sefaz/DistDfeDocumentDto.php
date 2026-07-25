<?php

namespace App\Domain\Sefaz;

/**
 * Um item docZip do retDistDFeInt.
 */
final readonly class DistDfeDocumentDto
{
    public function __construct(
        public int $nsu,
        public string $schema,
        public string $contentBase64,
        public string $schemaFamily,
    ) {}
}
