<?php

namespace App\Services\Fiscal\SimplesMei;

use App\DTO\Fiscal\FiscalAdapterRequest;
use App\DTO\Fiscal\FiscalAdapterResult;
use App\DTO\Serpro\IntegraResponse;
use App\Enums\FiscalRunResult;
use App\Enums\FiscalSituation;
use App\Models\DefisLatestDeclarationArtifact;
use App\Services\FiscalMonitoring\FiscalEvidenceStore;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Persistência fail-closed dos PDFs retornados pela DEFIS 143. */
final class DefisLatestDeclarationPostConsultService
{
    public const OPERATION_KEY = 'defis.consultimadecrec';

    public function __construct(
        private readonly DefisLatestDeclarationCodec $codec,
        private readonly FiscalEvidenceStore $evidenceStore,
    ) {}

    /** @return array{result:FiscalAdapterResult} */
    public function handle(FiscalAdapterRequest $request, IntegraResponse $response, FiscalAdapterResult $result): array
    {
        if ($result->result !== FiscalRunResult::Success || ! $response->success) {
            return ['result' => $result];
        }

        try {
            $year = $this->codec->assertCalendarYear($request->context['calendar_year']
                ?? $request->progress['calendar_year']
                ?? preg_replace('/^([0-9]{4}).*/', '$1', (string) ($request->competence?->period_key ?? '')));
            $decoded = $this->codec->decode($response->dados ?? $response->body['dados'] ?? null, $year);
            $documents = [];

            foreach ($decoded['documents'] as $document) {
                $evidence = $this->evidenceStore->store(
                    run: $request->run,
                    bytes: $document['bytes'],
                    contentType: 'application/pdf',
                    source: 'DEFIS_CONSULTIMADECREC143',
                    sourceVersion: SimplesMeiCatalog::DTO_VERSION,
                );
                $digest = hash('sha256', $decoded['calendar_year'].'|'.$document['kind'].'|'.$evidence->content_sha256);
                $artifact = DefisLatestDeclarationArtifact::query()->firstOrCreate([
                    'office_id' => $request->office->id,
                    'client_id' => $request->client->id,
                    'calendar_year' => $decoded['calendar_year'],
                    'kind' => $document['kind'],
                    'digest' => $digest,
                ], [
                    'fiscal_evidence_artifact_id' => $evidence->id,
                    'source_run_id' => $request->run->id,
                    'source_provenance' => $request->run->source_provenance?->value ?? 'UNVERIFIED',
                    'observed_at' => now(),
                    'filename' => 'defis-'.$decoded['calendar_year'].'-'.strtolower($document['kind']).'.pdf',
                    'content_type' => 'application/pdf',
                ]);
                $artifact->loadMissing('evidenceArtifact');
                $documents[] = $artifact->toPublicArray();
            }

            $normalized = is_array($result->normalized) ? $result->normalized : [];
            $normalized['calendar_year'] = $decoded['calendar_year'];
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
            Log::warning('defis.consultimadecrec.decode_failed', [
                'office_id' => $request->office->id,
                'client_id' => $request->client->id,
                'run_id' => $request->run->id,
                'code' => 'DEFIS_143_INVALID_RESPONSE',
                'exception' => $e::class,
            ]);

            return ['result' => FiscalAdapterResult::failed(
                'Resposta da última DEFIS inválida ou incompleta.',
                'DEFIS_143_INVALID_RESPONSE',
                $result->coverage,
            )];
        }
    }
}
