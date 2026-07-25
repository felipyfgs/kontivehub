<?php

namespace App\Enums;

/**
 * Estados independentes de fechamento, totalização, guia e pagamento FGTS.
 * Ausência de fonte oficial NÃO promove CONFIRMED/PRESENT — use UNKNOWN/UNSUPPORTED.
 */
enum FgtsIndependentState: string
{
    /** Evidência oficial positiva (ex.: S-1299 aceito). */
    case Confirmed = 'CONFIRMED';

    /** Evidência oficial de totalizador presente. */
    case Present = 'PRESENT';

    /** Esperado e ainda não observado (dentro ou após janela). */
    case Absent = 'ABSENT';

    /** Não consultado / sem evidência suficiente. */
    case Unknown = 'UNKNOWN';

    /** Sem API/fonte M2M oficial (ex.: guia e pagamento FGTS Digital). */
    case Unsupported = 'UNSUPPORTED';

    public function label(): string
    {
        return match ($this) {
            self::Confirmed => 'Confirmado',
            self::Present => 'Presente',
            self::Absent => 'Ausente',
            self::Unknown => 'Desconhecido / não consultado',
            self::Unsupported => 'Não suportado por fonte oficial',
        };
    }

    /** Guia e pagamento do portal FGTS Digital nunca são consultados. */
    public static function portalUnsupported(): self
    {
        return self::Unsupported;
    }
}
