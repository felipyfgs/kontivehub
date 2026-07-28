<?php

namespace App\Enums\Communication;

enum CommunicationOperationFailure: string
{
    case InboxSessionInvalid = 'INBOX_SESSION_INVALID';
    case OutboxTenantScopeInvalid = 'OUTBOX_TENANT_SCOPE_INVALID';
    case DisabledOrPeriodInvalid = 'COMMUNICATION_DISABLED_OR_PERIOD_INVALID';
    case WhatsappPreferenceDisabled = 'WHATSAPP_PREFERENCE_DISABLED';
    case DefaultInboxMissing = 'DEFAULT_INBOX_MISSING';
    case EligibleRecipientMissing = 'ELIGIBLE_RECIPIENT_MISSING';

    public function httpStatus(): int
    {
        return match ($this) {
            self::InboxSessionInvalid,
            self::OutboxTenantScopeInvalid => 409,
            default => 422,
        };
    }

    public function safeMessage(): string
    {
        return match ($this) {
            self::InboxSessionInvalid => 'Sessão da caixa de entrada inválida.',
            self::OutboxTenantScopeInvalid => 'Operação de saída incompatível com o tenant.',
            self::DisabledOrPeriodInvalid => 'Envio indisponível ou período inválido.',
            self::WhatsappPreferenceDisabled => 'Envio por WhatsApp desabilitado para o cliente.',
            self::DefaultInboxMissing => 'Caixa de entrada padrão não configurada.',
            self::EligibleRecipientMissing => 'Nenhum destinatário elegível foi encontrado.',
        };
    }
}
