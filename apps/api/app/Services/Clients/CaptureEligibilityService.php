<?php

namespace App\Services\Clients;

use App\Enums\CaptureChannel;
use App\Enums\CredentialStatus;
use App\Enums\OutboundFiscalModel;
use App\Enums\OutboundProfileStatus;
use App\Enums\SyncCursorStatus;
use App\Models\Client;
use App\Models\ClientCredential;
use App\Models\Establishment;
use App\Models\OutboundCaptureProfile;
use App\Models\SyncCursor;

/**
 * Regra central: Cliente ativo + Estabelecimento ativo + capture_enabled
 * + credencial válida + cursor não bloqueado.
 * Inclui resumo dos canais escriturais e elegibilidade do canal MA outbound.
 */
final class CaptureEligibilityService
{
    /**
     * @return array{
     *   eligible: bool,
     *   reasons: list<string>,
     *   reasons_codes: list<string>,
     *   channels: array<string, array{label: string, enabled: bool, eligible: bool}>
     * }
     */
    public function evaluate(Establishment $establishment, ?SyncCursor $cursor = null): array
    {
        $base = $this->evaluateBaseOnly($establishment, $cursor);
        $baseOk = $base['eligible'];

        return [
            'eligible' => $baseOk,
            'reasons' => $base['reasons'],
            'reasons_codes' => $base['reasons_codes'],
            'channels' => $this->channelSummary($baseOk, $establishment),
        ];
    }

    /**
     * @return array{eligible: bool, reasons: list<string>, reasons_codes: list<string>}
     */
    private function evaluateBaseOnly(Establishment $establishment, ?SyncCursor $cursor = null): array
    {
        $reasons = [];
        $codes = [];

        $client = $establishment->relationLoaded('client')
            ? $establishment->client
            : Client::query()->find($establishment->client_id);

        if ($client === null || ! $client->is_active) {
            $reasons[] = 'Cliente inativo ou indisponível.';
            $codes[] = 'client_inactive';
        }

        if (! $establishment->is_active) {
            $reasons[] = 'Estabelecimento inativo.';
            $codes[] = 'establishment_inactive';
        }

        if (! $establishment->capture_enabled) {
            $reasons[] = 'Captura desabilitada para este estabelecimento.';
            $codes[] = 'capture_disabled';
        }

        $credential = ClientCredential::query()
            ->where('client_id', $establishment->client_id)
            ->where('status', CredentialStatus::Active)
            ->first();

        if ($credential === null) {
            $reasons[] = 'certificado ausente ou inativa.';
            $codes[] = 'credential_missing';
        } elseif ($credential->valid_to !== null && $credential->valid_to->isPast()) {
            $reasons[] = 'certificado expirada.';
            $codes[] = 'credential_expired';
        }

        if ($cursor !== null && $cursor->status === SyncCursorStatus::Blocked) {
            $reasons[] = 'Cursor de sincronização bloqueado.';
            $codes[] = 'cursor_blocked';
        }

        return [
            'eligible' => $reasons === [],
            'reasons' => $reasons,
            'reasons_codes' => $codes,
        ];
    }

    public function isEligible(Establishment $establishment, ?SyncCursor $cursor = null): bool
    {
        return $this->evaluate($establishment, $cursor)['eligible'];
    }

    /**
     * Elegibilidade específica do canal MA (consulta / pacote / M2M / fallback mutante).
     *
     * @return array{
     *   base_eligible: bool,
     *   package_ingest: bool,
     *   protocol_query: bool,
     *   m2m_retrieval: bool,
     *   mutating_probe: bool,
     *   csc_required: bool,
     *   csc_configured: bool,
     *   reasons: list<string>,
     *   reasons_codes: list<string>
     * }
     */
    public function evaluateMaOutbound(
        Establishment $establishment,
        ?OutboundCaptureProfile $profile = null,
        ?OutboundFiscalModel $model = null,
    ): array {
        // Base sem resumo de canais (evita reentrância com channelSummary).
        $base = $this->evaluateBaseOnly($establishment);
        $reasons = $base['reasons'];
        $codes = $base['reasons_codes'];

        $uf = strtoupper((string) ($establishment->address_state ?? ''));
        if ($uf !== '' && $uf !== 'MA') {
            $reasons[] = 'Canal MA outbound exige estabelecimento UF MA.';
            $codes[] = 'uf_not_ma';
        }

        $channelEnabled = (bool) config('sefaz.ma_outbound.enabled', false);
        $queryEnabled = (bool) config('sefaz.ma_outbound.protocol_query_enabled', false);
        $m2mEnabled = (bool) config('sefaz.ma_outbound.m2m_retrieval_enabled', false)
            && (string) config('sefaz.ma_outbound.m2m_status', 'NO_GO_M2M') !== 'NO_GO_M2M';
        $mutatingEnabled = (bool) config('sefaz.ma_outbound.mutating_probe_enabled', false);
        $globalKill = (bool) config('sefaz.ma_outbound.kill_switch', false);

        if (! $channelEnabled) {
            $reasons[] = 'Feature SEFAZ_MA_OUTBOUND_ENABLED desligada.';
            $codes[] = 'ma_outbound_disabled';
        }

        if ($globalKill) {
            $reasons[] = 'Kill switch global MA outbound ativo.';
            $codes[] = 'ma_kill_switch_global';
        }

        $profileOk = true;
        $cscConfigured = false;
        if ($profile !== null) {
            if ($profile->kill_switch) {
                $reasons[] = 'Kill switch do perfil ativo.';
                $codes[] = 'ma_kill_switch_profile';
                $profileOk = false;
            }
            if (! $profile->allowlisted) {
                $reasons[] = 'CNPJ/perfil fora da allowlist.';
                $codes[] = 'not_allowlisted';
                $profileOk = false;
            }
            if (! $profile->consent_recorded || $profile->mandate_reference === null || $profile->mandate_reference === '') {
                $reasons[] = 'Mandato/consentimento do cliente não registrado.';
                $codes[] = 'mandate_missing';
                $profileOk = false;
            }
            if (! in_array($profile->status, [OutboundProfileStatus::Active, OutboundProfileStatus::SeedReady], true)) {
                $reasons[] = 'Perfil outbound não está ativo.';
                $codes[] = 'profile_inactive';
                $profileOk = false;
            }
            $cscConfigured = (bool) $profile->csc_configured;
            $model = $model ?? $profile->model;
        }

        $baseEligible = $base['eligible'] && $channelEnabled && ! $globalKill && $profileOk;

        // CSC só no fallback mutante modelo 65 — nunca em consulta 562 ou pacote oficial
        $cscRequired = $mutatingEnabled
            && $model === OutboundFiscalModel::Nfce;

        return [
            'base_eligible' => $baseEligible,
            'package_ingest' => $base['eligible'] && $channelEnabled && ! $globalKill,
            'protocol_query' => $baseEligible && $queryEnabled,
            'm2m_retrieval' => $baseEligible && $m2mEnabled,
            'mutating_probe' => $baseEligible && $mutatingEnabled && (! $cscRequired || $cscConfigured),
            'csc_required' => $cscRequired,
            'csc_configured' => $cscConfigured,
            'reasons' => array_values(array_unique($reasons)),
            'reasons_codes' => array_values(array_unique($codes)),
        ];
    }

    /**
     * @return array<string, array{label: string, enabled: bool, eligible: bool}>
     */
    private function channelSummary(bool $baseOk, Establishment $establishment): array
    {
        $out = [];
        foreach (CaptureChannel::operationalCases() as $channel) {
            $enabled = $channel->isEnabled();
            $eligible = $baseOk && $enabled;

            if ($channel === CaptureChannel::MaOutbound) {
                // Não chamar evaluateMaOutbound aqui (reentraria em evaluate).
                $uf = strtoupper((string) ($establishment->address_state ?? ''));
                $maFlag = (bool) config('sefaz.ma_outbound.enabled', false);
                $kill = (bool) config('sefaz.ma_outbound.kill_switch', false);
                $eligible = $baseOk && $maFlag && ! $kill && ($uf === '' || $uf === 'MA');
            }

            $out[$channel->value] = [
                'label' => $channel->label(),
                'enabled' => $enabled,
                'eligible' => $eligible,
            ];
        }

        return $out;
    }
}
