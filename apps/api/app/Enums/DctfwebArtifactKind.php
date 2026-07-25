<?php

namespace App\Enums;

/** Tipos de evidência versionada da DCTFWeb/MIT. */
enum DctfwebArtifactKind: string
{
    case Recibo = 'RECIBO';
    case Relatorio = 'RELATORIO';
    case Xml = 'XML';
    case Darf = 'DARF';
    case SituacaoMit = 'SITUACAO_MIT';
    case ApuracaoMit = 'APURACAO_MIT';
    case EncerramentoMit = 'ENCERRAMENTO_MIT';
    case Transmissao = 'TRANSMISSAO';

    public function contentTypeHint(): string
    {
        return match ($this) {
            self::Xml => 'application/xml',
            self::Darf, self::Recibo => 'application/pdf',
            default => 'application/json',
        };
    }
}
