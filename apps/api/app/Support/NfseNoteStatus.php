<?php

namespace App\Support;

/**
 * Situação da NFS-e Nacional (projeção): enum granular + label operacional de UI.
 *
 * cStat (documento): 100 Gerada · 101 Substituição Gerada · 102 Decisão judicial · 103 Avulsa.
 * Cancelamento / substituição da original: tipicamente por evento ADN.
 *
 * Camada operacional (lista/filtro/insight): Autorizada · Cancelada · Em revisão.
 */
final class NfseNoteStatus
{
    public const ACTIVE = 'ACTIVE';

    public const SUBSTITUTE = 'SUBSTITUTE';

    public const CANCELLED = 'CANCELLED';

    public const SUPERSEDED = 'SUPERSEDED';

    public const JUDICIAL = 'JUDICIAL';

    public const UNKNOWN = 'UNKNOWN';

    /**
     * @deprecated legado — usar SUPERSEDED ou JUDICIAL
     */
    public const REPLACED = 'REPLACED';

    /** Grupo operacional: nota válida para escrituração. */
    public const GROUP_AUTHORIZED = 'AUTHORIZED';

    /** Grupo operacional: nota inválida (cancelada ou supersedida). */
    public const GROUP_CANCELLED = 'CANCELLED';

    /** Grupo operacional: triagem / parse indefinido. */
    public const GROUP_REVIEW = 'REVIEW';

    /**
     * Mapa cStat (string numérica) → status operacional granular.
     */
    public static function fromCStat(?string $cStat): string
    {
        $code = $cStat !== null ? trim($cStat) : '';
        if ($code === '') {
            return self::UNKNOWN;
        }

        return match ($code) {
            '100' => self::ACTIVE,
            '101' => self::SUBSTITUTE,
            '102' => self::JUDICIAL,
            '103' => self::ACTIVE,
            default => self::UNKNOWN,
        };
    }

    /**
     * Descrição oficial curta do cStat (para UI/detalhe).
     */
    public static function cStatDescription(?string $cStat): ?string
    {
        $code = $cStat !== null ? trim($cStat) : '';
        if ($code === '') {
            return null;
        }

        return match ($code) {
            '100' => 'NFS-e Gerada',
            '101' => 'NFS-e de Substituição Gerada',
            '102' => 'NFS-e de Decisão Judicial',
            '103' => 'NFS-e Avulsa',
            default => 'Código de situação '.$code,
        };
    }

    /**
     * Grupo operacional (filtro / insight / badge).
     */
    public static function operationalGroup(string $status): string
    {
        return match (strtoupper(trim($status))) {
            self::ACTIVE, self::SUBSTITUTE, self::JUDICIAL => self::GROUP_AUTHORIZED,
            self::CANCELLED, self::SUPERSEDED, self::REPLACED => self::GROUP_CANCELLED,
            self::UNKNOWN => self::GROUP_REVIEW,
            default => self::GROUP_REVIEW,
        };
    }

    /**
     * Enums granulares pertencentes a um grupo operacional.
     *
     * @return list<string>
     */
    public static function statusesInGroup(string $group): array
    {
        return match (strtoupper(trim($group))) {
            self::GROUP_AUTHORIZED, 'AUTORIZADA' => [
                self::ACTIVE,
                self::SUBSTITUTE,
                self::JUDICIAL,
            ],
            self::GROUP_CANCELLED, 'CANCELADA' => [
                self::CANCELLED,
                self::SUPERSEDED,
                self::REPLACED,
            ],
            self::GROUP_REVIEW, 'UNKNOWN', 'EM_REVISAO', 'EM-REVISAO' => [
                self::UNKNOWN,
            ],
            default => [],
        };
    }

    /**
     * Resolve parâmetro de filtro `status` da API: grupo operacional ou enum único.
     *
     * - AUTHORIZED / AUTORIZADA → ACTIVE + SUBSTITUTE + JUDICIAL
     * - CANCELLED / CANCELADA (filtro UI) → CANCELLED + SUPERSEDED (+ REPLACED)
     * - REVIEW / UNKNOWN → UNKNOWN
     * - enum granular (ACTIVE, SUBSTITUTE, SUPERSEDED, …) → match exato
     *
     * @return list<string>
     */
    public static function statusesForFilter(string $value): array
    {
        $v = strtoupper(trim($value));
        if ($v === '') {
            return [];
        }

        // Grupos operacionais da UI (CANCELLED aqui = grupo, não só o enum).
        if (in_array($v, [self::GROUP_AUTHORIZED, 'AUTORIZADA'], true)) {
            return self::statusesInGroup(self::GROUP_AUTHORIZED);
        }
        if (in_array($v, [self::GROUP_CANCELLED, 'CANCELADA'], true)) {
            return self::statusesInGroup(self::GROUP_CANCELLED);
        }
        if (in_array($v, [self::GROUP_REVIEW, 'UNKNOWN', 'EM_REVISAO', 'EM-REVISAO'], true)) {
            return self::statusesInGroup(self::GROUP_REVIEW);
        }

        return [$v];
    }

    /**
     * Label operacional pt-BR (lista, chip, insight, export).
     */
    public static function label(string $status): string
    {
        return match (self::operationalGroup($status)) {
            self::GROUP_AUTHORIZED => 'Autorizada',
            self::GROUP_CANCELLED => 'Cancelada',
            self::GROUP_REVIEW => 'Em revisão',
            default => $status,
        };
    }

    /**
     * Nuance granular / oficial curta (detalhe), sem ser o chip da grade.
     */
    public static function granularLabel(string $status): string
    {
        return match (strtoupper(trim($status))) {
            self::ACTIVE => 'NFS-e Gerada',
            self::SUBSTITUTE => 'NFS-e de Substituição',
            self::CANCELLED => 'Cancelada por evento',
            self::SUPERSEDED, self::REPLACED => 'Substituída',
            self::JUDICIAL => 'Decisão judicial',
            self::UNKNOWN => 'Situação indefinida',
            default => $status,
        };
    }

    /**
     * Texto de situação oficial para detalhe: prefere cStat; senão nuance do enum.
     */
    public static function officialDescription(?string $status, ?string $cStat = null): ?string
    {
        $fromCStat = self::cStatDescription($cStat);
        if ($fromCStat !== null) {
            return $fromCStat;
        }
        if ($status === null || trim($status) === '') {
            return null;
        }

        return self::granularLabel($status);
    }

    /**
     * Deriva status a partir do tipo de evento ADN (tpEvento / descrição).
     * Cancelamento por substituição → SUPERSEDED; cancelamento simples → CANCELLED.
     */
    public static function fromEventType(?string $eventType): ?string
    {
        if ($eventType === null || trim($eventType) === '') {
            return null;
        }

        $n = strtoupper(trim($eventType));
        $compact = preg_replace('/\s+/', '', $n) ?? $n;

        // Cancelamento por substituição (antes do cancel genérico)
        if (
            str_contains($compact, 'SUBST')
            || str_contains($compact, 'SUBSTITU')
            || str_contains($n, 'SUBSTITUI')
            || $compact === 'E105102'
            || $compact === '105102'
            || $compact === '110112'
            || preg_match('/^1\s*05\s*1\s*02/', $n) === 1
        ) {
            return self::SUPERSEDED;
        }

        // Cancelamento (incl. deferido por análise fiscal)
        if (
            str_contains($compact, 'CANCEL')
            || $compact === 'E101101'
            || $compact === '101101'
            || $compact === 'E101'
            || $compact === '110111'
            || $compact === 'E105104'
            || $compact === '105104'
            || preg_match('/^1\s*01\s*1\s*01/', $n) === 1
            || preg_match('/^1\s*05\s*1\s*04/', $n) === 1
        ) {
            return self::CANCELLED;
        }

        // Solicitação de análise fiscal — não finaliza status
        if (
            str_contains($compact, 'ANALISE')
            || str_contains($compact, 'ANÁLISE')
            || $compact === 'E101103'
            || $compact === '101103'
        ) {
            return null;
        }

        return null;
    }
}
