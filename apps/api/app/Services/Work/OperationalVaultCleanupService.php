<?php

namespace App\Services\Work;

use App\Contracts\SecureObjectStore;
use App\Enums\SecureObjectPurpose;
use App\Enums\Work\GenerationBatchStatus;
use App\Enums\Work\OperationalExportStatus;
use App\Models\OperationalExport;
use App\Models\OperationalTaskEvidence;
use App\Models\ProcessGenerationBatch;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Limpeza segura de previews expirados, exports e evidências removidas.
 * Nunca varre objetos de outras finalidades do cofre.
 */
final class OperationalVaultCleanupService
{
    public function __construct(
        private readonly SecureObjectStore $store,
    ) {}

    /**
     * @return array{previews: int, exports: int, evidences: int}
     */
    public function run(): array
    {
        return [
            'previews' => $this->expirePreviews(),
            'exports' => $this->expireExports(),
            'evidences' => $this->purgeRemovedEvidences(),
        ];
    }

    public function expirePreviews(): int
    {
        $count = 0;
        ProcessGenerationBatch::query()
            ->where('status', GenerationBatchStatus::Previewed->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->orderBy('id')
            ->each(function (ProcessGenerationBatch $batch) use (&$count): void {
                $batch->forceFill(['status' => GenerationBatchStatus::Expired])->save();
                $count++;
            });

        return $count;
    }

    public function expireExports(): int
    {
        $count = 0;
        OperationalExport::query()
            ->whereIn('status', [OperationalExportStatus::Ready->value, OperationalExportStatus::Pending->value])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->orderBy('id')
            ->each(function (OperationalExport $export) use (&$count): void {
                if ($export->storage_path) {
                    try {
                        Storage::disk('local')->delete($export->storage_path);
                    } catch (Throwable $e) {
                        report($e);
                    }
                }
                $export->forceFill([
                    'status' => OperationalExportStatus::Expired,
                    'storage_path' => null,
                ])->save();
                $count++;
            });

        return $count;
    }

    /**
     * Remove do cofre apenas evidências já marcadas removed_at (purpose operacional).
     */
    public function purgeRemovedEvidences(): int
    {
        $count = 0;
        OperationalTaskEvidence::query()
            ->whereNotNull('removed_at')
            ->whereNotNull('vault_object_id')
            ->where('removed_at', '<', now()->subDays(7))
            ->orderBy('id')
            ->each(function (OperationalTaskEvidence $evidence) use (&$count): void {
                try {
                    // delete por object id — store não lista outros purposes
                    $this->store->delete($evidence->vault_object_id);
                    // Nullar para não reprocessar em toda execução de work:cleanup.
                    $evidence->forceFill(['vault_object_id' => null])->save();
                    $count++;
                    Log::info('work.evidence.vault_purged', [
                        'purpose' => SecureObjectPurpose::OperationalTaskEvidence->value,
                        'office_id' => $evidence->office_id,
                        'evidence_id' => $evidence->id,
                        // sem path / bytes / vault id
                    ]);
                } catch (Throwable $e) {
                    report($e);
                }
            });

        return $count;
    }
}
