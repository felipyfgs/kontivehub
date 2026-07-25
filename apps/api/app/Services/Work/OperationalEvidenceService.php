<?php

namespace App\Services\Work;

use App\Contracts\SecureObjectStore;
use App\Enums\SecureObjectPurpose;
use App\Enums\Work\TaskStatus;
use App\Models\OperationalTask;
use App\Models\OperationalTaskEvidence;
use App\Services\Audit\AuditLogger;
use App\Support\CurrentOffice;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Upload/download/remoção de evidências no cofre com AAD própria.
 */
final class OperationalEvidenceService
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
        private readonly CurrentOffice $currentOffice,
        private readonly SecureObjectStore $store,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * AAD canônico: purpose + office + task + evidence + sha256.
     *
     * @return array<string, scalar|null>
     */
    public static function aad(int $officeId, int $taskId, string $evidenceId, string $sha256): array
    {
        return SecureObjectPurpose::OperationalTaskEvidence->aadBase([
            'office_id' => $officeId,
            'task_id' => $taskId,
            'evidence_id' => $evidenceId,
            'sha256' => $sha256,
        ]);
    }

    public function upload(OperationalTask $task, UploadedFile $file): OperationalTaskEvidence
    {
        $officeId = (int) $this->currentOffice->id();
        if ((int) $task->office_id !== $officeId) {
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
            $evidence = OperationalTaskEvidence::query()->create([
                'office_id' => $officeId,
                'operational_task_id' => $task->id,
                'original_filename' => $filename,
                'mime_type' => $mime,
                'byte_size' => strlen($bytes),
                'sha256' => $sha256,
                'vault_object_id' => (string) Str::uuid(),
                'uploaded_by_membership_id' => $this->currentOffice->membership()?->id,
            ]);

            $aad = self::aad($officeId, (int) $task->id, (string) $evidence->id, $sha256);
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

    public function download(OperationalTaskEvidence $evidence): StreamedResponse
    {
        $officeId = (int) $this->currentOffice->id();
        if ((int) $evidence->office_id !== $officeId || $evidence->removed_at !== null) {
            abort(404);
        }

        $aad = self::aad(
            (int) $evidence->office_id,
            (int) $evidence->operational_task_id,
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

    public function remove(OperationalTaskEvidence $evidence, string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => ['Justificativa de remoção é obrigatória.'],
            ]);
        }

        $officeId = (int) $this->currentOffice->id();
        if ((int) $evidence->office_id !== $officeId || $evidence->removed_at !== null) {
            abort(404);
        }

        $task = OperationalTask::query()->findOrFail($evidence->operational_task_id);
        if ($task->requires_evidence && $task->status === TaskStatus::Concluida) {
            $activeCount = OperationalTaskEvidence::query()
                ->where('operational_task_id', $task->id)
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
                'removed_by_membership_id' => $this->currentOffice->membership()?->id,
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
