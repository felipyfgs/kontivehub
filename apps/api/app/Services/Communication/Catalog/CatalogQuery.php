<?php

namespace App\Services\Communication\Catalog;

use App\DTO\Communication\OutboundCapabilitiesData;
use App\DTO\Communication\OutboundCapabilityData;
use App\DTO\Communication\OutboundCapabilityVariantData;
use App\Enums\Communication\InboxStatus;
use App\Enums\Communication\OutboundCapabilityUnavailableReason;
use App\Models\CommunicationInbox;
use App\Models\CommunicationLabel;
use App\Models\User;
use App\Services\Communication\Authorization\Access;
use App\Support\CurrentTenant;
use Illuminate\Support\Collection;

final readonly class CatalogQuery
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private Access $access,
        private OutboundCapabilityEvaluator $capabilities,
    ) {}

    /** @return Collection<int, CommunicationLabel> */
    public function labels(): Collection
    {
        return CommunicationLabel::query()->orderBy('name')->get();
    }

    public function outboundCapabilities(
        ?User $actor = null,
        ?CommunicationInbox $inbox = null,
    ): OutboundCapabilitiesData {
        $tenant = $this->currentTenant->tenant();
        $contextReason = match (true) {
            ! (bool) config('communication.enabled') || ! (bool) config('communication.gateway.enabled') => OutboundCapabilityUnavailableReason::GatewayUnavailable,
            ! $tenant->communication_enabled || ! $tenant->is_active || ! $tenant->isOperational() => OutboundCapabilityUnavailableReason::TenantDisabled,
            $inbox !== null => $this->capabilities->contextReason($actor, $inbox),
            default => null,
        };
        $maxMediaBytes = (int) config('communication.media.max_bytes', 20_971_520);
        $contactsArray = $this->capabilities->feature(
            'contacts_array',
            OutboundCapabilityUnavailableReason::ContactsArrayBuilderUnimplemented,
        );
        $gif = $this->capabilities->feature(
            'gif',
            OutboundCapabilityUnavailableReason::GifPlaybackBuilderUnimplemented,
        );
        $ptv = $this->capabilities->feature(
            'ptv',
            OutboundCapabilityUnavailableReason::PtvBuilderUnimplemented,
        );
        $event = $this->capabilities->feature(
            'event',
            OutboundCapabilityUnavailableReason::EventBuilderUnimplemented,
        );
        $viewOnce = $this->capabilities->feature(
            'view_once',
            OutboundCapabilityUnavailableReason::ViewOnceBuilderUnimplemented,
        );
        $mediaBatch = $this->capabilities->feature(
            'media_batch',
            OutboundCapabilityUnavailableReason::MessageBatchUnimplemented,
        );
        $providerSearch = match (true) {
            config('communication.gif_provider.driver', 'disabled') === 'disabled' => new OutboundCapabilityVariantData(false, OutboundCapabilityUnavailableReason::GifProviderDisabled),
            ! $gif->enabled => new OutboundCapabilityVariantData(false, $gif->reason),
            default => new OutboundCapabilityVariantData(true),
        };

        $kinds = [
            'TEXT' => new OutboundCapabilityData(
                family: 'TEXT',
                enabled: true,
                limits: ['max_text_bytes' => 4096],
                variants: ['link_preview' => new OutboundCapabilityVariantData(true)],
                compatFields: ['max_text_bytes' => 4096, 'link_preview' => true],
            ),
            'IMAGE' => $this->mediaCapability('IMAGE', ['image/jpeg', 'image/png', 'image/webp'], $maxMediaBytes, [
                'caption' => new OutboundCapabilityVariantData(true),
                'camera' => new OutboundCapabilityVariantData(true),
                'view_once' => $viewOnce,
            ]),
            'AUDIO' => $this->mediaCapability('AUDIO', ['audio/ogg', 'audio/mpeg', 'audio/mp4', 'audio/webm'], $maxMediaBytes, [
                'ptt' => new OutboundCapabilityVariantData(true),
            ], ['ptt' => true], ['max_duration_seconds' => 3_600]),
            'VIDEO' => $this->mediaCapability('VIDEO', ['video/mp4', 'video/webm'], $maxMediaBytes, [
                'caption' => new OutboundCapabilityVariantData(true),
                'gif' => $gif,
                'provider_search' => $providerSearch,
                'ptv' => $ptv,
                'view_once' => $viewOnce,
            ], ['gif' => $gif->enabled]),
            'DOCUMENT' => $this->mediaCapability('DOCUMENT', ['application/pdf', 'text/plain', 'application/zip'], $maxMediaBytes, [
                'caption' => new OutboundCapabilityVariantData(true),
            ]),
            'STICKER' => $this->mediaCapability('STICKER', ['image/webp'], min(
                $maxMediaBytes,
                (int) config('communication.sticker_library.max_item_bytes', 1_048_576),
            ), [
                'library' => new OutboundCapabilityVariantData(
                    (bool) config('communication.sticker_library.enabled', false),
                    (bool) config('communication.sticker_library.enabled', false)
                        ? null
                        : OutboundCapabilityUnavailableReason::RolloutDisabled,
                ),
            ], [
                'library' => (bool) config('communication.sticker_library.enabled', false),
                'library_sources' => ['LOCAL_IMPORT', 'DEVICE_RECENT', 'DEVICE_FAVORITE', 'DEVICE_MESSAGE'],
                'device_sync_enabled' => (bool) config('communication.sticker_library.device_sync_enabled', false),
                'max_item_bytes' => (int) config('communication.sticker_library.max_item_bytes', 1_048_576),
            ]),
            'LOCATION' => new OutboundCapabilityData('LOCATION', true),
            'CONTACT' => new OutboundCapabilityData(
                family: 'CONTACT',
                enabled: true,
                limits: ['max_items' => $contactsArray->enabled ? 10 : 1],
                variants: ['multiple' => $contactsArray],
                compatFields: ['multiple' => $contactsArray->enabled],
            ),
            'POLL' => new OutboundCapabilityData(
                family: 'POLL',
                enabled: true,
                limits: ['max_options' => 12],
                compatFields: ['max_options' => 12],
            ),
            'INTERACTIVE' => new OutboundCapabilityData(
                family: 'INTERACTIVE',
                enabled: true,
                limits: ['max_options' => 20, 'modes' => ['BUTTONS', 'LIST']],
                compatFields: ['modes' => ['BUTTONS', 'LIST'], 'max_options' => 20],
            ),
            'MEDIA_BATCH' => new OutboundCapabilityData(
                family: 'MEDIA_BATCH',
                enabled: $mediaBatch->enabled,
                reason: $mediaBatch->reason,
                limits: [
                    'mime_types' => ['image/jpeg', 'image/png', 'image/webp', 'video/mp4', 'video/webm', 'application/pdf', 'text/plain', 'application/zip'],
                    'max_items' => 10,
                    'max_bytes' => $maxMediaBytes,
                ],
                variants: ['album_native' => new OutboundCapabilityVariantData(false, OutboundCapabilityUnavailableReason::NativeAlbumInteroperabilityUnverified)],
            ),
            'EVENT' => new OutboundCapabilityData('EVENT', $event->enabled, $event->reason),
            'GIF_PROVIDER_SEARCH' => new OutboundCapabilityData('GIF_PROVIDER_SEARCH', $providerSearch->enabled, $providerSearch->reason),
            'UNSUPPORTED' => new OutboundCapabilityData(
                family: 'UNSUPPORTED',
                enabled: false,
                reason: OutboundCapabilityUnavailableReason::MessageKindUnsupported,
                compatFields: ['error_code' => OutboundCapabilityUnavailableReason::MessageKindUnsupported->value],
            ),
        ];
        if ($contextReason !== null) {
            $kinds = array_map(
                static fn (OutboundCapabilityData $capability): OutboundCapabilityData => $capability->unavailable($contextReason),
                $kinds,
            );
        }

        return new OutboundCapabilitiesData(
            enabled: $contextReason === null,
            requiresPermission: 'communication.reply',
            kinds: $kinds,
            maxMediaBytes: $maxMediaBytes,
            conversationInitiation: $this->conversationInitiationCapability($actor),
        );
    }

    /**
     * @param  non-empty-string  $family
     * @param  list<string>  $mimeTypes
     * @param  array<string, OutboundCapabilityVariantData>  $variants
     * @param  array<string, bool|int|list<string>|string>  $compatFields
     */
    private function mediaCapability(
        string $family,
        array $mimeTypes,
        int $maxMediaBytes,
        array $variants = [],
        array $compatFields = [],
        array $additionalLimits = [],
    ): OutboundCapabilityData {
        return new OutboundCapabilityData(
            family: $family,
            enabled: true,
            limits: ['mime_types' => $mimeTypes, 'max_bytes' => $maxMediaBytes, ...$additionalLimits],
            variants: $variants,
            compatFields: ['mime_types' => $mimeTypes, ...$compatFields],
        );
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
