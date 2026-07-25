<?php

namespace App\Contracts;

use App\Models\Client;
use App\Models\Office;
use App\Models\TaxGuide;
use App\Models\TaxInstallmentParcel;

/**
 * Hook da central de guias para documentos de parcela (DARF/DAS).
 * Implementação stub tenant-scoped até tax-guide-management completo.
 */
interface TaxGuideEnrollment
{
    /**
     * Registra ou reutiliza guia a partir de documento de parcela.
     * Idempotente por office + chave lógica de emissão.
     *
     * @param  array{
     *     modality: string,
     *     order_external_id: string,
     *     parcel_key: string,
     *     document_type?: string,
     *     identifier?: string|null,
     *     amount_cents?: int|null,
     *     due_at?: string|null,
     *     valid_until?: string|null,
     *     content_sha256?: string|null,
     *     content_type?: string|null,
     *     vault_object_id?: string|null,
     *     source_system?: string,
     *     source_service?: string,
     *     source_operation?: string,
     *     correlation_id?: string|null,
     *     run_id?: int|null,
     *     evidence_artifact_id?: int|null,
     *     metadata?: array<string, mixed>
     * }  $document
     * @return array{guide: TaxGuide, reused: bool}
     */
    public function enrollFromInstallmentDocument(
        Office $office,
        Client $client,
        TaxInstallmentParcel $parcel,
        array $document,
    ): array;
}
