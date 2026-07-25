<?php

namespace App\Services\Outbound;

use App\DTO\Outbound\SvrsNfceEligibilityResult;
use App\Enums\OutboundFiscalModel;
use App\Enums\OutboundNumberStatus;
use App\Enums\OutboundProfileStatus;
use App\Enums\SvrsNfceFailureReason;
use App\Models\OutboundCaptureProfile;
use App\Models\OutboundNumberState;

/**
 * Avaliador de elegibilidade: UF 21, modelo 65, OUT, ambiente, perfil, allowlist e A1.
 */
final class SvrsNfceRetrievalEligibility
{
    public function __construct(
        private readonly SvrsNfceConfig $config,
        private readonly SvrsNfceKillSwitchService $killSwitch,
    ) {}

    public function evaluate(
        OutboundNumberState $number,
        OutboundCaptureProfile $profile,
        bool $a1Available = true,
    ): SvrsNfceEligibilityResult {
        if (! $this->config->retrievalEnabled()) {
            return SvrsNfceEligibilityResult::no(
                SvrsNfceFailureReason::ChannelDisabled,
                'Flag master desligada.',
            );
        }

        if ($this->killSwitch->isActive()) {
            return SvrsNfceEligibilityResult::no(
                SvrsNfceFailureReason::KillSwitch,
                'Kill switch SVRS ativo.',
            );
        }

        if ($profile->status !== OutboundProfileStatus::Active) {
            return SvrsNfceEligibilityResult::no(
                SvrsNfceFailureReason::NotEligible,
                'Perfil inativo.',
            );
        }

        if ($profile->kill_switch) {
            return SvrsNfceEligibilityResult::no(
                SvrsNfceFailureReason::KillSwitch,
                'Kill switch do perfil ativo.',
            );
        }

        if ($this->config->pilotAllowlistOnly() && ! $profile->allowlisted) {
            return SvrsNfceEligibilityResult::no(
                SvrsNfceFailureReason::NotEligible,
                'Perfil fora da allowlist de piloto.',
            );
        }

        $model = $profile->model instanceof OutboundFiscalModel
            ? $profile->model
            : OutboundFiscalModel::tryFrom((string) $profile->model);

        if ($model !== OutboundFiscalModel::Nfce) {
            return SvrsNfceEligibilityResult::no(
                SvrsNfceFailureReason::NotEligible,
                'Somente modelo 65 neste canal.',
            );
        }

        if (strtoupper((string) $profile->uf) !== 'MA') {
            return SvrsNfceEligibilityResult::no(
                SvrsNfceFailureReason::NotEligible,
                'UF do perfil não é MA.',
            );
        }

        if (! in_array((string) $profile->environment, ['production', 'homologation'], true)) {
            return SvrsNfceEligibilityResult::no(
                SvrsNfceFailureReason::NotEligible,
                'Ambiente desconhecido.',
            );
        }

        $status = $number->status instanceof OutboundNumberStatus
            ? $number->status
            : OutboundNumberStatus::tryFrom((string) $number->status);

        if (! in_array($status, [OutboundNumberStatus::KeyDiscovered, OutboundNumberStatus::XmlPending], true)) {
            return SvrsNfceEligibilityResult::no(
                SvrsNfceFailureReason::NotEligible,
                'Estado do número não elegível para recovery.',
            );
        }

        $key = $this->normalizeKey(
            (string) ($number->discovered_access_key ?: $number->candidate_access_key ?: '')
        );

        if ($key === null) {
            return SvrsNfceEligibilityResult::no(
                SvrsNfceFailureReason::NotEligible,
                'Chave de acesso ausente ou inválida.',
            );
        }

        // cUF (pos 0-1) e modelo (pos 20-21) na chave de 44
        $cuf = substr($key, 0, 2);
        $mod = substr($key, 20, 2);

        if ($cuf !== '21') {
            return SvrsNfceEligibilityResult::no(
                SvrsNfceFailureReason::NotEligible,
                'cUF da chave diferente de 21.',
            );
        }

        if ($mod !== '65') {
            return SvrsNfceEligibilityResult::no(
                SvrsNfceFailureReason::NotEligible,
                'Modelo da chave diferente de 65.',
            );
        }

        if (! $a1Available) {
            return SvrsNfceEligibilityResult::no(
                SvrsNfceFailureReason::A1Unavailable,
                'Credencial A1 da raiz indisponível.',
            );
        }

        return SvrsNfceEligibilityResult::yes();
    }

    public function normalizeKey(string $raw): ?string
    {
        $key = strtoupper(preg_replace('/[\s.\-\/]/', '', $raw) ?? '');
        if (! preg_match('/^[A-Z0-9]{44}$/', $key)) {
            return null;
        }

        return $key;
    }
}
