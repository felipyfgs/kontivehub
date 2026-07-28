<?php

namespace App\Actions\Import;

use App\DTO\Import\DocumentImportBatchAdmissionData;
use App\DTO\Import\DocumentImportBatchAdmissionResult;
use App\Exceptions\DocumentImportBatchApiException;
use App\Services\Audit\AuditLogger;
use App\Services\Import\DocumentImportBatchService;
use RuntimeException;
use Throwable;

final class AdmitDocumentImportBatchAction
{
    public function __construct(
        private readonly DocumentImportBatchService $batches,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(
        int $tenantId,
        DocumentImportBatchAdmissionData $data,
    ): DocumentImportBatchAdmissionResult {
        try {
            $result = $this->batches->admit(
                tenantId: $tenantId,
                actor: $data->actor,
                files: $data->files,
                clientId: $data->clientId,
                establishmentId: $data->establishmentId,
                idempotencyKey: $data->idempotencyKey,
            );
        } catch (RuntimeException $error) {
            $this->audit->record('documents.import_batch', 'FAILED', null, [
                'message' => $error->getMessage(),
            ]);

            throw DocumentImportBatchApiException::admissionRejected(
                $error->getMessage(),
            );
        } catch (Throwable $error) {
            report($error);
            $this->audit->record('documents.import_batch', 'FAILED', null, [
                'message' => 'Falha ao admitir lote de importação.',
            ]);

            throw DocumentImportBatchApiException::admissionFailed();
        }

        $batch = $result['batch'];
        $created = $result['created'];
        $this->audit->record(
            'documents.import_batch',
            $created ? 'SUCCESS' : 'IDEMPOTENT',
            $batch,
            [
                'public_id' => $batch->public_id,
                'status' => $batch->status->value,
                'file_count' => $batch->file_count,
            ],
        );

        return new DocumentImportBatchAdmissionResult($batch, $created);
    }
}
