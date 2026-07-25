<?php

namespace App\Services\Outbound;

use App\Enums\OutboundFiscalModel;
use App\Models\OutboundCaptureProfile;
use App\Models\OutboundSeriesCursor;
use App\Models\User;
use App\Support\CurrentOffice;

/**
 * Avaliador único dos gates mutantes (flag, mandato, ADMIN+2FA, allowlist, série fechada…).
 * Produção permanece desabilitada se qualquer gate falhar.
 */
final class MutatingProbeGateEvaluator
{
    public function __construct(
        private readonly OutboundKillSwitchService $killSwitch,
    ) {}

    /**
     * @return array{allowed: bool, reasons: list<string>, reasons_codes: list<string>}
     */
    public function evaluate(
        OutboundCaptureProfile $profile,
        OutboundSeriesCursor $series,
        ?User $user = null,
    ): array {
        $reasons = [];
        $codes = [];

        if (! (bool) config('sefaz.ma_outbound.mutating_probe_enabled', false)) {
            $reasons[] = 'SEFAZ_MA_MUTATING_PROBE_ENABLED desligada.';
            $codes[] = 'mutating_flag_off';
        }

        if ($this->killSwitch->isBlocked($profile)) {
            $reasons[] = 'Kill switch ativo.';
            $codes[] = 'kill_switch';
        }

        if (! $profile->allowlisted) {
            $reasons[] = 'Perfil fora da allowlist.';
            $codes[] = 'not_allowlisted';
        }

        if (! $profile->consent_recorded || ! $profile->mandate_reference) {
            $reasons[] = 'Mandato do cliente ausente.';
            $codes[] = 'mandate_missing';
        }

        if (! $series->series_closed_for_mutation) {
            $reasons[] = 'Série/período não fechados para mutação (coordenação ERP/PDV).';
            $codes[] = 'series_not_closed';
        }

        if ($series->erp_coordination_ref === null || $series->erp_coordination_ref === '') {
            $reasons[] = 'Referência de coordenação ERP/PDV ausente.';
            $codes[] = 'erp_coord_missing';
        }

        if ($profile->model === OutboundFiscalModel::Nfce && ! $profile->csc_configured) {
            $reasons[] = 'CSC obrigatório para fallback mutante modelo 65.';
            $codes[] = 'csc_missing';
        }

        $user ??= auth()->user();
        $role = app(CurrentOffice::class)->role();
        if ($role?->value !== 'ADMIN') {
            $reasons[] = 'Somente ADMIN com 2FA recente.';
            $codes[] = 'admin_required';
        }

        // 2FA recente é enforced pelo middleware EnsureAdminTwoFactor nas rotas

        return [
            'allowed' => $reasons === [],
            'reasons' => $reasons,
            'reasons_codes' => $codes,
        ];
    }
}
