<?php

namespace App\Services\Communication\StickerLibrary;

use App\DTO\Communication\GatewayEventData;
use App\Enums\Communication\GatewayEventType;
use App\Enums\Communication\StickerAvailability;
use App\Enums\Communication\StickerSource;
use App\Enums\Communication\StickerSyncStatus;
use App\Models\CommunicationInbox;
use App\Models\CommunicationStickerContent;
use App\Models\CommunicationStickerObservation;
use App\Models\CommunicationStickerSyncWatermark;
use App\Services\Communication\StickerLibrary\StickerMaterializationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

final class StickerObservationIngestor
{
    public function __construct(
        private StickerMaterializationService $materialization,
    ) {}

    /** @return array<string, mixed> */
    public function ingest(GatewayEventData $incoming, CommunicationInbox $inbox): array
    {
        $payload = $incoming->payload;
        $observedAt = Carbon::instance($incoming->occurredAt)->toImmutable();
        $observation = CommunicationStickerObservation::query()->withoutGlobalScope('tenant')
            ->where('inbox_id', $inbox->id)
            ->where('observation_id', $payload['observation_id'])
            ->lockForUpdate()
            ->first();

        if ($incoming->type === GatewayEventType::StickerFavoriteChanged) {
            $observation ??= CommunicationStickerObservation::query()->create([
                'tenant_id' => $inbox->tenant_id,
                'inbox_id' => $inbox->id,
                'observation_id' => $payload['observation_id'],
                'source' => StickerSource::DeviceFavorite,
                'availability' => StickerAvailability::IncompleteMetadata,
                'unavailable_reason' => 'STATE_ONLY_OBSERVATION',
                'last_observed_at' => $observedAt,
            ]);
            $currentFavoriteAt = $observation->device_favorite_observed_at;
            if ($currentFavoriteAt === null || $observedAt->greaterThanOrEqualTo($currentFavoriteAt)) {
                $observation->forceFill([
                    'device_favorite' => (bool) $payload['favorite'],
                    'device_favorite_observed_at' => $observedAt,
                    'last_observed_at' => max($observedAt, $observation->last_observed_at),
                    'metadata_encrypted' => $this->safeMetadata($payload),
                ])->save();
            }
        } else {
            $source = StickerSource::from((string) $payload['source']);
            $gatewayAvailability = StickerAvailability::from((string) $payload['availability']);
            $content = isset($payload['content_digest'])
                ? CommunicationStickerContent::query()->withoutGlobalScope('tenant')
                    ->where('tenant_id', $inbox->tenant_id)
                    ->where('sha256', $payload['content_digest'])
                    ->first()
                : null;
            $availability = $content !== null
                ? StickerAvailability::Available
                : ($gatewayAvailability === StickerAvailability::Available
                    ? StickerAvailability::PendingMaterialization
                    : $gatewayAvailability);
            $reason = match ($availability) {
                StickerAvailability::PendingMaterialization => 'MATERIALIZATION_NOT_AVAILABLE',
                StickerAvailability::IncompleteMetadata => 'INCOMPLETE_METADATA',
                StickerAvailability::Unsupported => 'UNSUPPORTED_METADATA',
                default => null,
            };

            if ($observation === null) {
                $observation = CommunicationStickerObservation::query()->create([
                    'tenant_id' => $inbox->tenant_id,
                    'inbox_id' => $inbox->id,
                    'content_id' => $content?->id,
                    'observation_id' => $payload['observation_id'],
                    'source' => $source,
                    'availability' => $availability,
                    'unavailable_reason' => $reason,
                    'metadata_encrypted' => $this->safeMetadata($payload),
                    'last_observed_at' => $observedAt,
                    'expires_at' => now()->addDays(max(1, (int) config('communication.sticker_library.retention_days', 30))),
                ]);
            } elseif ($observedAt->greaterThanOrEqualTo($observation->last_observed_at)) {
                $observation->forceFill([
                    'content_id' => $content?->id ?? $observation->content_id,
                    'source' => $source,
                    'availability' => $content !== null ? StickerAvailability::Available : $availability,
                    'unavailable_reason' => $content !== null ? null : $reason,
                    'metadata_encrypted' => $this->safeMetadata($payload),
                    'last_observed_at' => $observedAt,
                    'removed_at' => null,
                ])->save();
            }
        }

        CommunicationStickerSyncWatermark::query()->withoutGlobalScope('tenant')->updateOrCreate(
            ['tenant_id' => $inbox->tenant_id, 'inbox_id' => $inbox->id],
            [
                'status' => StickerSyncStatus::Partial,
                'reason_code' => 'OBSERVATION_BASED_SYNC',
                'last_gateway_event_id' => $incoming->gatewayEventId,
                'last_observed_at' => $observedAt,
                'failed_at' => null,
            ],
        );
        Log::info('communication.sticker.observed', [
            'tenant_id' => (int) $inbox->tenant_id,
            'inbox_id' => (int) $inbox->id,
            'sticker_id' => $observation->public_id,
            'source' => $observation->source->value,
            'availability' => $observation->availability->value,
            'favorite_change' => $incoming->type === GatewayEventType::StickerFavoriteChanged,
        ]);

        if ((bool) config('communication.sticker_library.device_sync_enabled', false)) {
            $this->materialization->requestIfNeeded($inbox, $observation->fresh() ?? $observation);
        }

        return [
            'sticker_id' => $observation->public_id,
            'source' => $observation->source->value,
            'availability' => $observation->availability->value,
            'partial_sync' => true,
        ];
    }

    /** @param array<string, mixed> $payload @return array<string, int|string|bool> */
    private function safeMetadata(array $payload): array
    {
        return array_intersect_key($payload, array_flip([
            'mime_type', 'size_bytes', 'width', 'height', 'is_lottie', 'content_digest',
        ]));
    }
}
