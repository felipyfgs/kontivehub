<?php

namespace App\Services\Fiscal\SimplesMei;

use App\DTO\Fiscal\FiscalAdapterRequest;
use App\DTO\Fiscal\FiscalAdapterResult;
use App\DTO\Serpro\IntegraResponse;
use App\Enums\FiscalRunResult;
use App\Enums\FiscalSituation;
use App\Models\DefisDeclarationReference;
use App\Models\DefisSpecificDeclarationArtifact;
use App\Services\FiscalMonitoring\FiscalEvidenceStore;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Persistência fail-closed dos PDFs retornados pela DEFIS 144. */
final class DefisSpecificDeclarationPostConsultService
{
    public const OPERATION_KEY = 'defis.consdecrec';

    public function __construct(
        private readonly DefisSpecificDeclarationCodec $codec,
        private readonly FiscalEvidenceStore $evidenceStore,
    ) {}

    /** @return array{result:FiscalAdapterResult} */
    public function handle(FiscalAdapterRequest $request, IntegraResponse $response, FiscalAdapterResult $result): array
    {
        if ($result->result !== FiscalRunResult::Success || ! $response->success) {
            return ['result' => $result];
        }

        try {
            $referenceId = $request->context['defis_reference_id'] ?? $request->progress['defis_reference_id'] ?? null;
            if (! is_int($referenceId) && ! (is_string($referenceId) && ctype_digit($referenceId))) {
                throw new \RuntimeException('Referência DEFIS ausente.');
            }
            $reference = DefisDeclarationReference::query()->withoutGlobalScopes()
                ->where('office_id', $request->office->id)->where('client_id', $request->client->id)->find((int) $referenceId);
            if ($reference === null) {
                throw new \RuntimeException('Referência DEFIS indisponível.');
            }
            $decoded = $this->codec->decode($response->dados ?? $response->body['dados'] ?? null);
            $documents = [];

            foreach ($decoded['documents'] as $document) {
                $evidence = $this->evidenceStore->store(
                    run: $request->run,
                    bytes: $document['bytes'],
                    contentType: 'application/pdf',
                    source: 'DEFIS_CONSDECREC144',
                    sourceVersion: SimplesMeiCatalog::DTO_VERSION,
                );
                $digest = hash('sha256', $reference->id.'|'.$document['kind'].'|'.$evidence->content_sha256);
                $artifact = DefisSpecificDeclarationArtifact::query()->firstOrCreate([
                    'office_id' => $request->office->id,
                    'client_id' => $request->client->id,
                    'defis_declaration_reference_id' => $reference->id,
                    'kind' => $document['kind'],
                    'digest' => $digest,
                ], [
                    'fiscal_evidence_artifact_id' => $evidence->id,
                    'source_run_id' => $request->run->id,
                    'source_provenance' => $request->run->source_provenance?->value ?? 'UNVERIFIED',
                    'observed_at' => now(),
                    'filename' => 'defis-'.$reference->id.'-'.strtolower($document['kind']).'.pdf',
                    'content_type' => 'application/pdf',
                ]);
                $artifact->loadMissing('evidenceArtifact');
                $documents[] = $artifact->toPublicArray();
            }

            $normalized = is_array($result->normalized) ? $result->normalized : [];
            $normalized['documents'] = $documents;

            return ['result' => new FiscalAdapterResult(
                result: $result->result,
                situation: FiscalSituation::UpToDate,
                coverage: $result->coverage,
                evidenceBytes: $result->evidenceBytes,
                evidenceContentType: $result->evidenceContentType,
                sourceVersion: $result->sourceVersion,
                normalized: $normalized,
                findings: $result->findings,
                itemsProcessed: $result->itemsProcessed,
                pagesProcessed: $result->pagesProcessed,
            )];
        } catch (Throwable $e) {
            Log::warning('defis.consdecrec.decode_failed', [
                'office_id' => $request->office->id,
                'client_id' => $request->client->id,
                'run_id' => $request->run->id,
                'code' => 'DEFIS_144_INVALID_RESPONSE',
                'exception' => $e::class,
            ]);

            return ['result' => FiscalAdapterResult::failed(
                'Resposta da declaração DEFIS específica inválida ou incompleta.',
                'DEFIS_144_INVALID_RESPONSE',
                $result->coverage,
            )];
        }
    }
}
