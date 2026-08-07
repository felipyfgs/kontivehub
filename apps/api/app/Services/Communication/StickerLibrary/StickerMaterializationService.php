<?php

namespace App\Services\Communication\StickerLibrary;

use App\Contracts\CommunicationTransport;
use App\DTO\Communication\GatewayEventData;
use App\Enums\Communication\GatewayCommandType;
use App\Enums\Communication\StickerAvailability;
use App\Enums\Communication\StickerSource;
use App\Exceptions\CommunicationTransportException;
use App\Models\CommunicationInbox;
use App\Models\CommunicationStickerContent;
use App\Models\CommunicationStickerObservation;
use App\Services\Communication\Media\MediaStore;
use App\Services\Communication\Outbox\OutboxService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

final class StickerMaterializationService
{
    public function __construct(
        private OutboxService $outbox,
        private CommunicationTransport $transport,
        private MediaStore $media,
        private StickerMediaInspector $inspector,
        private StickerQuota $quota,
    ) {}

    public function requestIfNeeded(CommunicationInbox $inbox, CommunicationStickerObservation $observation): void
    {
        if (! (bool) config('communication.sticker_library.device_sync_enabled', false)) {
            return;
        }
        if ($observation->availability !== StickerAvailability::PendingMaterialization) {
            return;
        }
        $meta = is_array($observation->metadata_encrypted) ? $observation->metadata_encrypted : [];
        $digest = strtolower((string) ($meta['content_digest'] ?? ''));
        if (preg_match('/^[a-f0-9]{64}$/', $digest) !== 1) {
            $observation->forceFill([
                'availability' => StickerAvailability::IncompleteMetadata,
                'unavailable_reason' => 'INCOMPLETE_METADATA',
            ])->save();

            return;
        }

        $commandId = 'sticker-materialize-'.$observation->observation_id;
        $this->outbox->enqueue(
            $inbox,
            GatewayCommandType::MaterializeSticker,
            [
                'observation_id' => $observation->observation_id,
                'expected_sha256' => $digest,
                'expected_mime_type' => 'image/webp',
                'max_bytes' => max(1, (int) config('communication.sticker_library.max_item_bytes', 1_048_576)),
            ],
            commandId: $commandId,
            effectKey: 'sticker-materialize:'.$inbox->id.':'.$observation->observation_id,
        );
    }

    /** @return array<string, mixed> */
    public function commit(GatewayEventData $incoming, CommunicationInbox $inbox): array
    {
        $payload = $incoming->payload;
        $observation = CommunicationStickerObservation::query()->withoutGlobalScope('tenant')
            ->where('inbox_id', $inbox->id)
            ->where('observation_id', (string) $payload['observation_id'])
            ->lockForUpdate()
            ->first();
        if ($observation === null) {
            return ['ignored' => true, 'reason' => 'OBSERVATION_NOT_FOUND'];
        }

        if (($payload['status'] ?? null) !== 'READY') {
            $observation->forceFill([
                'availability' => StickerAvailability::Expired,
                'unavailable_reason' => (string) ($payload['error_code'] ?? 'MATERIALIZATION_FAILED'),
            ])->save();
            Log::info('communication.sticker.unavailable', [
                'tenant_id' => (int) $inbox->tenant_id,
                'inbox_id' => (int) $inbox->id,
                'sticker_id' => $observation->public_id,
                'reason' => $observation->unavailable_reason,
            ]);

            return [
                'sticker_id' => $observation->public_id,
                'availability' => $observation->availability->value,
            ];
        }

        $tmp = tempnam(sys_get_temp_dir(), 'sticker-');
        if ($tmp === false) {
            throw new RuntimeException('Não foi possível criar spool temporário de figurinha.');
        }

        try {
            $stream = $this->transport->downloadMedia((string) $payload['spool_id']);
            $handle = fopen($tmp, 'wb');
            if (! is_resource($handle)) {
                throw new RuntimeException('Não foi possível abrir spool temporário de figurinha.');
            }
            try {
                while (! $stream->eof()) {
                    $chunk = $stream->read(65_536);
                    if ($chunk === '') {
                        break;
                    }
                    fwrite($handle, $chunk);
                }
            } finally {
                fclose($handle);
                if (method_exists($stream, 'close')) {
                    $stream->close();
                }
            }

            $descriptor = $this->inspector->inspect(
                $tmp,
                (string) ($payload['mime_type'] ?? 'image/webp'),
                strtolower((string) $payload['sha256']),
            );
            if ($descriptor->sizeBytes !== (int) $payload['size_bytes']) {
                throw new RuntimeException('Tamanho materializado diverge do descriptor.');
            }

            $content = DB::transaction(function () use ($inbox, $observation, $tmp, $descriptor, $payload): CommunicationStickerContent {
                $existing = CommunicationStickerContent::query()->withoutGlobalScope('tenant')
                    ->where('tenant_id', $inbox->tenant_id)
                    ->where('sha256', $descriptor->sha256)
                    ->first();
                if ($existing !== null) {
                    $observation->forceFill([
                        'content_id' => $existing->id,
                        'availability' => StickerAvailability::Available,
                        'unavailable_reason' => null,
                    ])->save();
                    Log::info('communication.sticker.deduplicated', [
                        'tenant_id' => (int) $inbox->tenant_id,
                        'inbox_id' => (int) $inbox->id,
                        'sticker_id' => $observation->public_id,
                    ]);

                    return $existing;
                }

                $this->quota->assertCanAdd((int) $inbox->tenant_id, $descriptor->sizeBytes);
                $source = fopen($tmp, 'rb');
                if (! is_resource($source)) {
                    throw new RuntimeException('Não foi possível reabrir o WebP materializado.');
                }
                try {
                    $stored = $this->media->putStream($source, [
                        'tenant_id' => (int) $inbox->tenant_id,
                        'inbox_id' => (int) $inbox->id,
                        'gateway_event_id' => $payload['command_id'] ?? $observation->observation_id,
                        'sha256' => $descriptor->sha256,
                    ]);
                } finally {
                    fclose($source);
                }
                if (! hash_equals($descriptor->sha256, $stored['sha256'])
                    || $descriptor->sizeBytes !== $stored['size_bytes']) {
                    $this->media->delete($stored['object_id']);
                    throw new RuntimeException('Integridade divergente após armazenamento privado.');
                }

                $content = CommunicationStickerContent::query()->create([
                    'tenant_id' => $inbox->tenant_id,
                    'sha256' => $descriptor->sha256,
                    'object_id_encrypted' => $stored['object_id'],
                    'storage_context_encrypted' => [
                        'tenant_id' => (int) $inbox->tenant_id,
                        'inbox_id' => (int) $inbox->id,
                        'sticker_materialize_id' => $observation->observation_id,
                    ],
                    'mime_type' => 'image/webp',
                    'size_bytes' => $descriptor->sizeBytes,
                    'width' => $descriptor->width,
                    'height' => $descriptor->height,
                    'animated' => $descriptor->animated,
                    'provenance' => $observation->source instanceof StickerSource
                        ? $observation->source
                        : StickerSource::DeviceRecent,
                    'retention_protected' => false,
                    'expires_at' => now()->addDays(max(1, (int) config('communication.sticker_library.retention_days', 30))),
                ]);
                $observation->forceFill([
                    'content_id' => $content->id,
                    'availability' => StickerAvailability::Available,
                    'unavailable_reason' => null,
                ])->save();
                Log::info('communication.sticker.materialized', [
                    'tenant_id' => (int) $inbox->tenant_id,
                    'inbox_id' => (int) $inbox->id,
                    'sticker_id' => $observation->public_id,
                    'deduplicated' => false,
                ]);

                return $content;
            });

            return [
                'sticker_id' => $observation->public_id,
                'content_id' => $content->public_id,
                'availability' => StickerAvailability::Available->value,
            ];
        } catch (CommunicationTransportException|ValidationException|Throwable $error) {
            $observation->forceFill([
                'availability' => StickerAvailability::IntegrityFailed,
                'unavailable_reason' => 'MATERIALIZATION_COMMIT_FAILED',
            ])->save();
            Log::warning('communication.sticker.unavailable', [
                'tenant_id' => (int) $inbox->tenant_id,
                'inbox_id' => (int) $inbox->id,
                'sticker_id' => $observation->public_id,
                'reason' => 'MATERIALIZATION_COMMIT_FAILED',
            ]);
            throw $error;
        } finally {
            @unlink($tmp);
        }
    }
}
