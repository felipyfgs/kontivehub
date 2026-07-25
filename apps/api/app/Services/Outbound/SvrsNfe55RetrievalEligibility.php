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
 * Elegibilidade NF-e 55: UF 21, modelo 55, OUT, ambiente, perfil, allowlist e A1.
 * Canal separado da NFC-e 65, mesmo governador de egress.
 */
final class SvrsNfe55RetrievalEligibility
{
    public function __construct(
        private readonly SvrsNfe55Config $config,
        private readonly SvrsNfe55KillSwitchService $killSwitch,
    ) {}

    public function evaluate(
        OutboundNumberState $number,
        OutboundCaptureProfile $profile,
        bool $a1Available = true,
    ): SvrsNfceEligibilityResult {
        if (! $this->config->retrievalEnabled()) {
            return SvrsNfceEligibilityResult::no(
                SvrsNfceFailureReason::ChannelDisabled,
                'Flag master NF-e 55 desligada.',
            );
        }

        if ($this->killSwitch->isActive()) {
            return SvrsNfceEligibilityResult::no(
                SvrsNfceFailureReason::KillSwitch,
                'Kill switch SVRS NF-e 55 ativo.',
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

        if ($model !== OutboundFiscalModel::Nfe) {
            return SvrsNfceEligibilityResult::no(
                SvrsNfceFailureReason::NotEligible,
                'Somente modelo 55 neste canal.',
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

        if (substr($key, 0, 2) !== '21') {
            return SvrsNfceEligibilityResult::no(
                SvrsNfceFailureReason::NotEligible,
                'cUF da chave diferente de 21.',
            );
        }

        if (substr($key, 20, 2) !== '55') {
            return SvrsNfceEligibilityResult::no(
                SvrsNfceFailureReason::NotEligible,
                'Modelo da chave diferente de 55.',
            );
        }

        // DV da chave (módulo 11) — barreira local antes de rede
        if (! $this->accessKeyDvValid($key)) {
            return SvrsNfceEligibilityResult::no(
                SvrsNfceFailureReason::NotEligible,
                'DV da chave inválido.',
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

    private function accessKeyDvValid(string $key): bool
    {
        $body = substr($key, 0, 43);
        $dv = (int) substr($key, 43, 1);
        if (! ctype_digit($body)) {
            // alfanumérico: validação completa em outro leiaute; aceita formato 44 e DV numérico
            return ctype_digit((string) $dv);
        }
        $weights = [2, 3, 4, 5, 6, 7, 8, 9];
        $sum = 0;
        $w = 0;
        for ($i = 42; $i >= 0; $i--) {
            $sum += (int) $body[$i] * $weights[$w % 8];
            $w++;
        }
        $mod = $sum % 11;
        $calc = $mod === 0 || $mod === 1 ? 0 : 11 - $mod;

        return $calc === $dv;
    }
}
