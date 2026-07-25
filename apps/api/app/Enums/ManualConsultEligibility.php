<?php

namespace App\Enums;

/**
 * Estado de elegibilidade de uma ação de consulta manual (somente-leitura).
 */
enum ManualConsultEligibility: string
{
    case Ready = 'ready';
    case ModuleOff = 'module_off';
    case CapabilityOff = 'capability_off';
    case TokenMissing = 'token_missing';
    case PowerMissing = 'power_missing';
    case PowerRefreshing = 'power_refreshing';
    case AdapterMissing = 'adapter_missing';
    case MutatingBlocked = 'mutating_blocked';
    case PermissionDenied = 'permission_denied';

    public function label(): string
    {
        return match ($this) {
            self::Ready => 'Pronta para consultar',
            self::ModuleOff => 'Módulo desabilitado para o escritório',
            self::CapabilityOff => 'Capability SERPRO desabilitada',
            self::TokenMissing => 'Token do procurador ausente ou expirado',
            self::PowerMissing => 'Poder e-CAC exigido ausente',
            self::PowerRefreshing => 'Verificando procuração no e-CAC',
            self::AdapterMissing => 'Adapter de consulta não implementado',
            self::MutatingBlocked => 'Operação mutante bloqueada neste explorador',
            self::PermissionDenied => 'Sem permissão para atualizar consultas fiscais',
        };
    }

    public function isExecutable(): bool
    {
        return $this === self::Ready;
    }
}
