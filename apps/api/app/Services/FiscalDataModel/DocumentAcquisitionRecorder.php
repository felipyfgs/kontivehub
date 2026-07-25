<?php

namespace App\Services\FiscalDataModel;

use App\Enums\DocumentAcquisitionSource;
use App\Models\DfeDocument;
use App\Models\DocumentAcquisition;
use App\Models\DocumentInterest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Grava document_acquisition na mesma transação da chegada e liga ao interesse.
 * Idempotente por (dfe_document_id, source, sha256) e por NSU de canal quando houver.
 */
final class DocumentAcquisitionRecorder
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public function record(
        DfeDocument $document,
        DocumentAcquisitionSource $source,
        string $sha256,
        ?int $establishmentId = null,
        ?int $nsu = null,
        ?string $channel = null,
        ?DocumentInterest $interest = null,
        array $extra = [],
    ): DocumentAcquisition {
        $wantsCanonical = $document->sha256 === $sha256;
        $attrs = array_merge([
            'office_id' => $document->office_id,
            'dfe_document_id' => $document->id,
            'access_key' => $document->access_key,
            'source' => $source->value,
            'channel' => $channel,
            'sha256' => $sha256,
            'nsu' => $nsu,
            'establishment_id' => $establishmentId,
            'is_canonical' => false,
            'bytes_diverge_from_canonical' => false,
        ], $extra);

        // Divergência de hash para mesma access_key canônica → quarentena sem promover canônico.
        if (! $wantsCanonical && $document->access_key) {
            $attrs['is_canonical'] = false;
            $attrs['bytes_diverge_from_canonical'] = true;
            $attrs['quarantine_reason'] = $attrs['quarantine_reason'] ?? 'sha256_diverges_from_canonical_document';
        }

        // Já existe canônico com este SHA? Reutilizar e não criar segundo canônico.
        $existingCanonicalSameSha = DocumentAcquisition::query()
            ->where('dfe_document_id', $document->id)
            ->where('sha256', $sha256)
            ->where('is_canonical', true)
            ->first();

        if ($existingCanonicalSameSha !== null && $existingCanonicalSameSha->source === $source->value) {
            $acquisition = $existingCanonicalSameSha;
        } else {
            // second acquisition same SHA different source: non-canonical unless no canonical yet
            $hasAnyCanonical = DocumentAcquisition::query()
                ->where('dfe_document_id', $document->id)
                ->where('is_canonical', true)
                ->exists();

            if ($wantsCanonical && ! $hasAnyCanonical) {
                $attrs['is_canonical'] = true;
            } elseif ($wantsCanonical && $hasAnyCanonical && $existingCanonicalSameSha === null) {
                // Mesmo SHA que o documento, mas já há canônico de outra fonte → gravar não-canônico.
                $attrs['is_canonical'] = false;
            }

            $acquisition = DocumentAcquisition::query()->firstOrCreate(
                [
                    'dfe_document_id' => $document->id,
                    'source' => $source->value,
                    'sha256' => $sha256,
                ],
                $attrs,
            );

            // Promover a esta linha se for a primeira canônica válida e ainda não canônica.
            if ($wantsCanonical && ! $acquisition->is_canonical) {
                $otherCanonical = DocumentAcquisition::query()
                    ->where('dfe_document_id', $document->id)
                    ->where('is_canonical', true)
                    ->where('id', '!=', $acquisition->id)
                    ->exists();
                if (! $otherCanonical) {
                    $acquisition->forceFill([
                        'is_canonical' => true,
                        'bytes_diverge_from_canonical' => false,
                    ])->save();
                }
            }
        }

        if ($interest !== null && Schema::hasTable('document_acquisition_interests')) {
            DB::table('document_acquisition_interests')->updateOrInsert(
                [
                    'document_acquisition_id' => $acquisition->id,
                    'document_interest_id' => $interest->id,
                ],
                [
                    'office_id' => $document->office_id,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }

        return $acquisition->fresh() ?? $acquisition;
    }
}
