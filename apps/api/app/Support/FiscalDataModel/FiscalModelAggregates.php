<?php

namespace App\Support\FiscalDataModel;

/**
 * Identificadores estáveis dos agregados da consolidação do modelo fiscal.
 */
final class FiscalModelAggregates
{
    public const TENANCY_CADASTRO = 'tenancy_cadastro';

    public const DOCUMENTOS_CURSORES = 'documentos_cursores';

    public const OUTBOUND = 'outbound';

    public const SERPRO = 'serpro';

    public const MONITORAMENTO_GUIAS = 'monitoramento_guias';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::TENANCY_CADASTRO,
            self::DOCUMENTOS_CURSORES,
            self::OUTBOUND,
            self::SERPRO,
            self::MONITORAMENTO_GUIAS,
        ];
    }

    public static function isKnown(string $aggregate): bool
    {
        return in_array($aggregate, self::all(), true);
    }
}
