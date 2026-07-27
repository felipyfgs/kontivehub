<?php

namespace App\Services\Work;

use App\Contracts\SecureObjectStore;
use App\Enums\SecureObjectPurpose;
use App\Enums\Work\TaskStatus;
use App\Models\WorkTask;
use App\Models\WorkTaskEvidence;
use App\Services\Audit\AuditLogger;
use App\Support\CurrentTenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Upload/download/remoção de evidências no cofre com AAD própria.
 */
final class WorkEvidenceService
{
    public const MAX_BYTES = 20 * 1024 * 1024; // 20 MiB

    /** @var list<string> */
    public const ALLOWED_MIMES = [
        'application/pdf',
        'image/png',
        'image/jpeg',
        'text/plain',
    ];

    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly SecureObjectStore $store,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * AAD canônico: purpose + tenant + task + evidence + sha256.
     *
     * @return array<string, scalar|null>
     */
    public static function aad(int $tenantId, int $taskId, string $evidenceId, string $sha256): array
    {
        return SecureObjectPurpose::WorkTaskEvidence->aadBase([
            'tenant_id' => $tenantId,
            'task_id' => $taskId,
            'evidence_id' => $evidenceId,
            'sha256' => $sha256,
        ]);
    }

    public function upload(WorkTask $task, UploadedFile $file): WorkTaskEvidence
    {
        $tenantId = (int) $this->currentTenant->id();
        if ((int) $task->tenant_id !== $tenantId) {
            abort(404);
        }

        if ($file->getSize() === false || $file->getSize() > self::MAX_BYTES) {
            throw ValidationException::withMessages([
                'file' => ['Arquivo excede o limite de 20 MiB.'],
            ]);
        }

        $bytes = file_get_contents($file->getRealPath());
        if ($bytes === false) {
            throw ValidationException::withMessages(['file' => ['Falha ao ler o arquivo.']]);
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->buffer($bytes) ?: 'application/octet-stream';
        if (! in_array($mime, self::ALLOWED_MIMES, true)) {
            throw ValidationException::withMessages([
                'file' => ['Tipo de arquivo não permitido. Use PDF, PNG, JPEG ou texto.'],
            ]);
        }

        $sha256 = hash('sha256', $bytes);
        $filename = $this->sanitizeFilename($file->getClientOriginalName() ?: 'evidence.bin');
        $objectId = null;

        try {
            // Linha primeiro (id estável no AAD); placeholder único em vault_object_id.
            $evidence = WorkTaskEvidence::query()->create([
                'tenant_id' => $tenantId,
                'work_task_id' => $task->id,
                'original_filename' => $filename,
                'mime_type' => $mime,
                'byte_size' => strlen($bytes),
                'sha256' => $sha256,
                'vault_object_id' => (string) Str::ulid(),
                'uploaded_by_membership_id' => $this->currentTenant->realMembership()?->id,
            ]);

            $aad = self::aad($tenantId, (int) $task->id, (string) $evidence->id, $sha256);
            $objectId = $this->store->put($bytes, $aad);
            $evidence->forceFill(['vault_object_id' => $objectId])->save();

            $this->audit->record('work.evidence.upload', 'SUCCESS', $evidence, [
                'task_id' => $task->id,
                'mime_type' => $mime,
                'byte_size' => strlen($bytes),
                'sha256' => $sha256,
            ]);

            return $evidence->fresh();
        } catch (Throwable $e) {
            if ($objectId !== null) {
                try {
                    $this->store->delete($objectId);
                } catch (Throwable) {
                }
            }
            throw $e;
        }
    }

    public function download(WorkTaskEvidence $evidence): StreamedResponse
    {
        $tenantId = (int) $this->currentTenant->id();
        if ((int) $evidence->tenant_id !== $tenantId || $evidence->removed_at !== null) {
            abort(404);
        }

        $aad = self::aad(
            (int) $evidence->tenant_id,
            (int) $evidence->work_task_id,
            (string) $evidence->id,
            $evidence->sha256,
        );

        $plaintext = $this->store->get($evidence->vault_object_id, $aad);

        $filename = $evidence->original_filename;

        return response()->streamDownload(function () use ($plaintext): void {
            echo $plaintext;
        }, $filename, [
            'Content-Type' => $evidence->mime_type,
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    public function remove(WorkTaskEvidence $evidence, string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => ['Justificativa de remoção é obrigatória.'],
            ]);
        }

        $tenantId = (int) $this->currentTenant->id();
        if ((int) $evidence->tenant_id !== $tenantId || $evidence->removed_at !== null) {
            abort(404);
        }

        $task = WorkTask::query()->findOrFail($evidence->work_task_id);
        if ($task->requires_evidence && $task->status === TaskStatus::Concluida) {
            $activeCount = WorkTaskEvidence::query()
                ->where('work_task_id', $task->id)
                ->whereNull('removed_at')
                ->count();
            if ($activeCount <= 1) {
                throw ValidationException::withMessages([
                    'evidence' => ['Não é possível remover a única evidência de tarefa concluída que a exige.'],
                ]);
            }
        }

        DB::transaction(function () use ($evidence, $reason, $task): void {
            $evidence->forceFill([
                'removed_at' => now(),
                'removal_reason' => $reason,
                'removed_by_membership_id' => $this->currentTenant->realMembership()?->id,
            ])->save();

            try {
                $this->store->delete($evidence->vault_object_id);
            } catch (Throwable $e) {
                report($e);
            }

            $this->audit->record('work.evidence.remove', 'SUCCESS', $evidence, [
                'task_id' => $task->id,
                'reason' => $reason,
            ]);
        });
    }

    private function sanitizeFilename(string $name): string
    {
        $name = basename(str_replace(["\0", '\\'], '', $name));
        $name = preg_replace('/[^\w.\- ()\p{L}]+/u', '_', $name) ?? 'evidence.bin';
        $name = trim($name, '._ ');

        return mb_substr($name !== '' ? $name : 'evidence.bin', 0, 200);
    }
}
