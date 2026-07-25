<?php

namespace App\Services\Communication\Gateway;

use App\Enums\Communication\GatewayCommandType;
use App\Enums\TenantPermission;

final class CommunicationGatewayOperationPolicy
{
    /** @param array<string, mixed> $payload */
    public function permissionFor(GatewayCommandType $type, array $payload = []): TenantPermission
    {
        if ($type === GatewayCommandType::UpdateChatState
            && in_array(strtoupper(trim((string) ($payload['action'] ?? ''))), ['SYNC', 'MARK_CLEAN'], true)) {
            return TenantPermission::CommunicationManageInboxes;
        }

        return match ($type) {
            GatewayCommandType::SendMessage,
            GatewayCommandType::EditMessage,
            GatewayCommandType::RevokeMessage,
            GatewayCommandType::ReactMessage,
            GatewayCommandType::VotePoll,
            GatewayCommandType::MarkMessage,
            GatewayCommandType::RequestUnavailableMessage,
            GatewayCommandType::RequestMediaRetry,
            GatewayCommandType::SubscribePresence,
            GatewayCommandType::SetChatPresence,
            GatewayCommandType::SetChatDisappearing,
            GatewayCommandType::UpdateChatState => TenantPermission::CommunicationReply,

            GatewayCommandType::ProvisionSession,
            GatewayCommandType::PairSession,
            GatewayCommandType::PairPhone,
            GatewayCommandType::RespondPasskey,
            GatewayCommandType::ConfirmPasskey,
            GatewayCommandType::ConnectSession,
            GatewayCommandType::DisconnectSession,
            GatewayCommandType::ResetSession,
            GatewayCommandType::SetPassive,
            GatewayCommandType::LogoutSession,
            GatewayCommandType::SetPresence,
            GatewayCommandType::UpdateBlocklist,
            GatewayCommandType::UpdatePrivacy,
            GatewayCommandType::SetDefaultDisappearing,
            GatewayCommandType::RequestHistorySync => TenantPermission::CommunicationManageInboxes,
        };
    }

    public function requiresConnectedInbox(GatewayCommandType $type): bool
    {
        return match ($type) {
            GatewayCommandType::ProvisionSession,
            GatewayCommandType::PairSession,
            GatewayCommandType::PairPhone,
            GatewayCommandType::RespondPasskey,
            GatewayCommandType::ConfirmPasskey,
            GatewayCommandType::ConnectSession,
            GatewayCommandType::DisconnectSession,
            GatewayCommandType::ResetSession,
            GatewayCommandType::SetPassive,
            GatewayCommandType::LogoutSession => false,
            default => true,
        };
    }

    public function allowsDisabledInbox(GatewayCommandType $type): bool
    {
        return in_array($type, [
            GatewayCommandType::DisconnectSession,
            GatewayCommandType::LogoutSession,
        ], true);
    }
}
