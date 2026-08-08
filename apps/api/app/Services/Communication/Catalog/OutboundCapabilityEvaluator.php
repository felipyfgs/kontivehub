<?php

namespace App\Services\Communication\Catalog;

use App\DTO\Communication\OutboundCapabilityVariantData;
use App\Enums\Communication\InboxStatus;
use App\Enums\Communication\OutboundCapabilityUnavailableReason;
use App\Exceptions\CommunicationConversationApiException;
use App\Models\CommunicationInbox;
use App\Models\User;
use App\Services\Communication\Authorization\Access;

final readonly class OutboundCapabilityEvaluator
{
    public function __construct(private Access $access) {}

    public function contextReason(?User $actor, CommunicationInbox $inbox): ?OutboundCapabilityUnavailableReason
    {
        if (! (bool) config('communication.enabled') || ! (bool) config('communication.gateway.enabled')) {
            return OutboundCapabilityUnavailableReason::GatewayUnavailable;
        }

        $inbox->loadMissing('tenant');
        if (! $inbox->tenant?->communication_enabled
            || ! $inbox->tenant->is_active
            || ! $inbox->tenant->isOperational()) {
            return OutboundCapabilityUnavailableReason::TenantDisabled;
        }
        if ($actor === null || ! $this->access->canReply($actor, $inbox)) {
            return OutboundCapabilityUnavailableReason::PermissionDenied;
        }
        if (! $inbox->is_enabled) {
            return OutboundCapabilityUnavailableReason::InboxDisabled;
        }
        if ($inbox->status !== InboxStatus::Connected) {
            return OutboundCapabilityUnavailableReason::InboxDisconnected;
        }

        return null;
    }

    public function feature(
        string $feature,
        OutboundCapabilityUnavailableReason $builderUnavailableReason,
    ): OutboundCapabilityVariantData {
        if (! (bool) config("communication.outbound_builders.{$feature}", false)) {
            return new OutboundCapabilityVariantData(false, $builderUnavailableReason);
        }
        if (! (bool) config("communication.outbound_features.{$feature}", false)) {
            return new OutboundCapabilityVariantData(false, OutboundCapabilityUnavailableReason::RolloutDisabled);
        }

        return new OutboundCapabilityVariantData(true);
    }

    public function assertFeatureEnabled(
        string $feature,
        OutboundCapabilityUnavailableReason $builderUnavailableReason,
    ): void {
        $capability = $this->feature($feature, $builderUnavailableReason);
        if (! $capability->enabled) {
            throw CommunicationConversationApiException::unsupportedMessageKind(
                'A família outbound solicitada está indisponível: '.$capability->reason?->value.'.',
            );
        }
    }
}
