<?php

namespace App\Enums;

/** Códigos de bloqueio da policy de operação mutante (sanitizados). */
enum FiscalMutationDenialCode: string
{
    case KillSwitch = 'KILL_SWITCH';
    case FeatureDisabled = 'FEATURE_DISABLED';
    case MutatingDisabled = 'MUTATING_DISABLED';
    case OperationCohortDisabled = 'OPERATION_COHORT_DISABLED';
    case RoleForbidden = 'ROLE_FORBIDDEN';
    case TotpRequired = 'TOTP_REQUIRED';
    case TotpExpired = 'TOTP_EXPIRED';
    /** Reconfirmação de senha (contexto platform_privileged). */
    case PasswordConfirmationRequired = 'PASSWORD_CONFIRMATION_REQUIRED';
    case PasswordConfirmationExpired = 'PASSWORD_CONFIRMATION_EXPIRED';
    case SubscriptionBlocked = 'SUBSCRIPTION_BLOCKED';
    case ServiceNotCataloged = 'SERVICE_NOT_CATALOGED';
    case CatalogNotMutating = 'CATALOG_NOT_MUTATING';
    case CatalogDisabled = 'CATALOG_DISABLED';
    case ProxyPowerMissing = 'PROXY_POWER_MISSING';
    case ProxyPowerRevoked = 'PROXY_POWER_REVOKED';
    case EligibilityBlocked = 'ELIGIBILITY_BLOCKED';
    case BudgetExceeded = 'BUDGET_EXCEEDED';
    case CostBlocked = 'COST_BLOCKED';
    case UncertainResultOpen = 'UNCERTAIN_RESULT_OPEN';
    case AntiRepeatWindow = 'ANTI_REPEAT_WINDOW';
    case PreflightExpired = 'PREFLIGHT_EXPIRED';
    case ConfirmationRequired = 'CONFIRMATION_REQUIRED';
    case ContributorCrossTenant = 'CONTRIBUTOR_CROSS_TENANT';
    case IdempotencyConflict = 'IDEMPOTENCY_CONFLICT';
    case RetryBlocked = 'RETRY_BLOCKED';
    case NotFound = 'NOT_FOUND';
    /** Perfil demo / somente leitura — sem efeito fiscal externo. */
    case DemoMode = 'DEMO_MODE';

    public function message(): string
    {
        return match ($this) {
            self::KillSwitch => 'Kill switch de mutações fiscais ativo.',
            self::FeatureDisabled => 'Hub/módulo fiscal desabilitado.',
            self::MutatingDisabled => 'Operações mutantes desabilitadas.',
            self::OperationCohortDisabled => 'Operação não liberada para esta coorte/tenant.',
            self::RoleForbidden => 'Papel sem permissão para mutação fiscal.',
            self::TotpRequired => 'Confirmação TOTP necessária.',
            self::TotpExpired => 'Confirmação TOTP expirada; confirme novamente.',
            self::PasswordConfirmationRequired => 'Reconfirmação de senha necessária para ação privilegiada.',
            self::PasswordConfirmationExpired => 'Reconfirmação de senha expirada; confirme novamente.',
            self::SubscriptionBlocked => 'Assinatura do escritório bloqueia mutações.',
            self::ServiceNotCataloged => 'Operação não catalogada ou inexistente.',
            self::CatalogNotMutating => 'Operação não é mutante no catálogo.',
            self::CatalogDisabled => 'Operação mutante desabilitada no catálogo.',
            self::ProxyPowerMissing => 'Procuração/poder ausente para o serviço.',
            self::ProxyPowerRevoked => 'Poder de procuração revogado ou inválido.',
            self::EligibilityBlocked => 'Elegibilidade Integra bloqueada.',
            self::BudgetExceeded => 'Franquia/orçamento insuficiente.',
            self::CostBlocked => 'Custo estimado bloqueia a operação.',
            self::UncertainResultOpen => 'Há resultado incerto aberto; reconcilie antes de repetir.',
            self::AntiRepeatWindow => 'Janela anti-repetição ativa para esta operação.',
            self::PreflightExpired => 'Preflight expirado; execute novo preflight.',
            self::ConfirmationRequired => 'Confirmação explícita da consequência fiscal exigida.',
            self::ContributorCrossTenant => 'Contribuinte de outro tenant.',
            self::IdempotencyConflict => 'Chave de idempotência em conflito.',
            self::RetryBlocked => 'Retry bloqueado para este estado; use reconciliação.',
            self::NotFound => 'Operação não encontrada.',
            self::DemoMode => 'Modo demonstração/somente leitura: mutações fiscais externas bloqueadas.',
        };
    }
}
