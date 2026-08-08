<?php

namespace App\Actions\Communication;

use App\Enums\Communication\StickerAvailability;
use App\Enums\Communication\StickerSource;
use App\Models\CommunicationInbox;
use App\Models\CommunicationStickerContent;
use App\Models\CommunicationStickerObservation;
use App\Models\Tenant;
use App\Services\Communication\Media\MediaStore;
use App\Services\Communication\StickerLibrary\StickerMediaInspector;
use App\Services\Communication\StickerLibrary\StickerQuota;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final readonly class ImportStickerAction
{
    public function __construct(
        private StickerMediaInspector $inspector,
        private StickerQuota $quota,
        private MediaStore $media,
    ) {}

    public function handle(CommunicationInbox $inbox, UploadedFile $upload): CommunicationStickerObservation
    {
        $path = $upload->getRealPath();
        if (! is_string($path) || $path === '') {
            throw new \RuntimeException('Upload temporário indisponível.');
        }
        $descriptor = $this->inspector->inspect($path, $upload->getClientMimeType());
        $stored = null;

        try {
            return DB::transaction(function () use ($inbox, $path, $descriptor, &$stored): CommunicationStickerObservation {
                Tenant::query()->withoutGlobalScopes()->whereKey($inbox->tenant_id)->lockForUpdate()->firstOrFail();
                $content = CommunicationStickerContent::query()->withoutGlobalScope('tenant')
                    ->where('tenant_id', $inbox->tenant_id)
                    ->where('sha256', $descriptor->sha256)
                    ->first();
                $deduplicated = $content !== null;

                if ($content === null) {
                    $this->quota->assertCanAdd((int) $inbox->tenant_id, $descriptor->sizeBytes);
                    $stream = fopen($path, 'rb');
                    if (! is_resource($stream)) {
                        throw new \RuntimeException('Não foi possível abrir o WebP validado.');
                    }
                    $storageContext = [
                        'tenant_id' => (int) $inbox->tenant_id,
                        'inbox_id' => (int) $inbox->id,
                        'sticker_import_id' => (string) Str::ulid(),
                    ];
                    try {
                        $stored = $this->media->putStream($stream, $storageContext);
                    } finally {
                        fclose($stream);
                    }
                    if (! hash_equals($descriptor->sha256, $stored['sha256'])
                        || $descriptor->sizeBytes !== $stored['size_bytes']) {
                        throw new \RuntimeException('Integridade divergente após armazenamento privado.');
                    }
                    $content = CommunicationStickerContent::query()->create([
                        'tenant_id' => $inbox->tenant_id,
                        'sha256' => $descriptor->sha256,
                        'object_id_encrypted' => $stored['object_id'],
                        'storage_context_encrypted' => $storageContext,
                        'mime_type' => $descriptor->mime,
                        'size_bytes' => $descriptor->sizeBytes,
                        'width' => $descriptor->width,
                        'height' => $descriptor->height,
                        'animated' => $descriptor->animated,
                        'provenance' => StickerSource::LocalImport,
                        'retention_protected' => true,
                    ]);
                }

                $observation = CommunicationStickerObservation::query()->withoutGlobalScope('tenant')
                    ->where('tenant_id', $inbox->tenant_id)
                    ->where('inbox_id', $inbox->id)
                    ->where('content_id', $content->id)
                    ->where('source', StickerSource::LocalImport)
                    ->first();
                if ($observation === null) {
                    $observation = CommunicationStickerObservation::query()->create([
                        'tenant_id' => $inbox->tenant_id,
                        'inbox_id' => $inbox->id,
                        'content_id' => $content->id,
                        'observation_id' => 'local:'.Str::ulid(),
                        'source' => StickerSource::LocalImport,
                        'availability' => StickerAvailability::Available,
                        'last_observed_at' => now(),
                    ]);
                } else {
                    $observation->forceFill([
                        'availability' => StickerAvailability::Available,
                        'unavailable_reason' => null,
                        'removed_at' => null,
                        'expires_at' => null,
                        'last_observed_at' => now(),
                    ])->save();
                }

                Log::info('communication.sticker.materialized', [
                    'tenant_id' => (int) $inbox->tenant_id,
                    'inbox_id' => (int) $inbox->id,
                    'sticker_id' => $observation->public_id,
                    'size_bytes' => $descriptor->sizeBytes,
                    'deduplicated' => $deduplicated,
                    'source' => StickerSource::LocalImport->value,
                ]);

                return $observation->load('content');
            }, 3);
        } catch (Throwable $error) {
            if (is_array($stored)) {
                $this->media->delete($stored['object_id']);
            }
            throw $error;
        }
    }
}
