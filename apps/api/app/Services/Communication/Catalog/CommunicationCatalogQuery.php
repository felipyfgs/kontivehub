<?php

namespace App\Services\Communication\Catalog;

use App\Enums\Communication\InboxStatus;
use App\Models\CommunicationInbox;
use App\Models\CommunicationLabel;
use App\Models\User;
use App\Services\Communication\Authorization\CommunicationAccess;
use App\Support\CurrentTenant;
use Illuminate\Support\Collection;

final readonly class CommunicationCatalogQuery
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private CommunicationAccess $access,
    ) {}

    /** @return Collection<int, CommunicationLabel> */
    public function labels(): Collection
    {
        return CommunicationLabel::query()->orderBy('name')->get();
    }

    /** @return array<string, mixed> */
    public function outboundCapabilities(?User $actor = null): array
    {
        $enabled = (bool) config('communication.enabled')
            && (bool) config('communication.gateway.enabled')
            && (bool) $this->currentTenant->tenant()->communication_enabled;

        return [
            'enabled' => $enabled,
            'requires_permission' => 'communication.reply',
            'kinds' => [
                'TEXT' => ['supported' => true, 'max_text_bytes' => 4096, 'link_preview' => true],
                'IMAGE' => ['supported' => true, 'mime_types' => ['image/jpeg', 'image/png', 'image/webp']],
                'AUDIO' => ['supported' => true, 'ptt' => true, 'mime_types' => ['audio/ogg', 'audio/mpeg', 'audio/mp4', 'audio/webm']],
                'VIDEO' => ['supported' => true, 'gif' => false, 'mime_types' => ['video/mp4', 'video/webm']],
                'DOCUMENT' => ['supported' => true, 'mime_types' => ['application/pdf', 'text/plain', 'application/zip']],
                'STICKER' => ['supported' => true, 'mime_types' => ['image/webp']],
                'LOCATION' => ['supported' => true],
                'CONTACT' => ['supported' => true, 'multiple' => false],
                'POLL' => ['supported' => true, 'max_options' => 12],
                'INTERACTIVE' => ['supported' => true, 'modes' => ['BUTTONS', 'LIST'], 'max_options' => 20],
                'UNSUPPORTED' => ['supported' => false, 'error_code' => 'MESSAGE_KIND_UNSUPPORTED'],
            ],
            'max_media_bytes' => (int) config('communication.media.max_bytes', 20_971_520),
            'conversation_initiation' => $this->conversationInitiationCapability($actor),
        ];
    }

    /** @return array{enabled:bool,reason:string|null,requires_permission:string} */
    private function conversationInitiationCapability(?User $actor): array
    {
        $tenant = $this->currentTenant->tenant();
        $allowlisted = array_map('intval', (array) config('communication.outbound_conversation.allowed_tenant_ids', []));
        $reason = match (true) {
            ! (bool) config('communication.outbound_conversation.enabled', false) => 'rollout_disabled',
            (bool) config('communication.outbound_conversation.kill_switch', true) => 'kill_switch_active',
            ! (bool) config('communication.outbound_conversation.allow_all_tenants', false) && ! in_array((int) $tenant->id, $allowlisted, true) => 'tenant_not_allowlisted',
            ! (bool) config('communication.enabled') || ! (bool) config('communication.gateway.enabled') => 'gateway_unavailable',
            ! $tenant->is_active || ! $tenant->isOperational() || ! (bool) $tenant->communication_enabled => 'tenant_disabled',
            default => null,
        };

        if ($reason === null) {
            $replyState = $this->actorReplyState($actor);
            $reason = match (true) {
                ! $replyState['can_reply'] => 'permission_denied',
                ! $replyState['has_operational_inbox'] => 'inbox_unavailable',
                default => null,
            };
        }

        return ['enabled' => $reason === null, 'reason' => $reason, 'requires_permission' => 'communication.reply'];
    }

    /** @return array{can_reply:bool,has_operational_inbox:bool} */
    private function actorReplyState(?User $actor): array
    {
        if ($actor === null) {
            return ['can_reply' => false, 'has_operational_inbox' => false];
        }

        $inboxes = CommunicationInbox::query()
            ->whereIn('id', $this->access->visibleInboxIds($actor))
            ->get();
        $replyable = $inboxes->filter(
            fn (CommunicationInbox $inbox): bool => $this->access->canReply($actor, $inbox),
        );

        return [
            'can_reply' => $replyable->isNotEmpty(),
            'has_operational_inbox' => $replyable->contains(
                fn (CommunicationInbox $inbox): bool => $inbox->is_enabled
                    && $inbox->status === InboxStatus::Connected,
            ),
        ];
    }
}
