<?php

namespace App\Services\Esocial;

use App\Enums\EsocialEventCode;
use App\Enums\FiscalFindingSeverity;
use App\Enums\FiscalSituation;
use App\Models\EsocialEventEvidence;

/**
 * Findings de divergência SOMENTE sobre dados eSocial conhecidos.
 * MUST NOT declarar débito, guia ou pendência do portal FGTS Digital.
 */
final class FgtsEsocialDivergenceAnalyzer
{
    /** Códigos de finding proibidos (débito portal / FGTS Digital). */
    public const FORBIDDEN_FINDING_CODES = [
        'FGTS_DIGITAL_DEBT',
        'FGTS_PORTAL_DEBT',
        'FGTS_GUIDE_OVERDUE',
        'FGTS_PAYMENT_MISSING',
        'FGTS_DIGITAL_PENDING',
        'DEBITO_FGTS_DIGITAL',
    ];

    /**
     * @param  list<EsocialEventEvidence>  $evidences
     * @return list<array{code:string,severity:string,title:string,detail:string,situation:string,creates_pending:bool}>
     */
    public function analyze(array $evidences, string $competencePeriodKey): array
    {
        $findings = [];

        $byCode = [];
        foreach ($evidences as $ev) {
            $code = $ev->event_code instanceof EsocialEventCode
                ? $ev->event_code->value
                : (string) $ev->event_code;
            $byCode[$code][] = $ev;
        }

        // Múltiplos totalizadores com bases distintas na mesma competência → inconsistência eSocial.
        $totalizerShas = [];
        foreach ([EsocialEventCode::S5003->value, EsocialEventCode::S5013->value] as $tc) {
            foreach ($byCode[$tc] ?? [] as $ev) {
                $totalizerShas[$ev->content_sha256] = true;
            }
        }
        if (count($totalizerShas) > 1) {
            $findings[] = [
                'code' => 'ESOCIAL_TOTALIZER_INCONSISTENT',
                'severity' => FiscalFindingSeverity::Medium->value,
                'title' => 'Totalizadores eSocial inconsistentes',
                'detail' => sprintf(
                    'Competência %s possui múltiplas evidências de totalizador (S-5003/S-5013) com conteúdo distinto. Revisar eventos oficiais — sem inferir débito do portal FGTS Digital.',
                    $competencePeriodKey,
                ),
                'situation' => FiscalSituation::Attention->value,
                'creates_pending' => true,
            ];
        }

        // Fechamento e totalizador presentes: apenas confirma base conhecida (sem finding de débito).
        // Ausência pós-janela é tratada no projector.

        // Sanidade: nunca emitir findings de débito portal.
        return array_values(array_filter(
            $findings,
            fn (array $f) => ! in_array(strtoupper($f['code']), self::FORBIDDEN_FINDING_CODES, true)
                && ! $this->declaresFgtsDigitalDebt($f),
        ));
    }

    /**
     * @param  array{code:string,title?:string,detail?:string}  $finding
     */
    public function declaresFgtsDigitalDebt(array $finding): bool
    {
        $blob = strtoupper(
            ($finding['code'] ?? '').' '.($finding['title'] ?? '').' '.($finding['detail'] ?? '')
        );

        // Permitir menção negativa ("não declara débito").
        if (str_contains($blob, 'NÃO DECLARA') || str_contains($blob, 'NAO DECLARA')
            || str_contains($blob, 'SEM INFERIR DÉBITO') || str_contains($blob, 'SEM INFERIR DEBITO')) {
            return false;
        }

        $debtHints = [
            'DÉBITO DO PORTAL FGTS',
            'DEBITO DO PORTAL FGTS',
            'DÉBITO FGTS DIGITAL',
            'DEBITO FGTS DIGITAL',
            'FGTS DIGITAL EM ABERTO',
            'GUIA FGTS VENCIDA',
            'PENDÊNCIA DO PORTAL FGTS',
            'PENDENCIA DO PORTAL FGTS',
        ];

        foreach ($debtHints as $hint) {
            if (str_contains($blob, $hint)) {
                return true;
            }
        }

        return in_array(strtoupper((string) ($finding['code'] ?? '')), self::FORBIDDEN_FINDING_CODES, true);
    }
}
