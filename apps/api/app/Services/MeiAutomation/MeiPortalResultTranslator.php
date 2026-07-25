<?php

namespace App\Services\MeiAutomation;

use App\DTO\Fiscal\FiscalAdapterRequest;
use App\DTO\Fiscal\FiscalAdapterResult;
use App\Enums\FiscalCoverage;
use App\Enums\FiscalFindingSeverity;
use App\Enums\FiscalRunResult;
use App\Enums\FiscalSituation;
use App\Enums\FiscalSourceProvenance;
use App\Enums\MeiProvider;
use App\Models\MeiAutomationAttempt;

final class MeiPortalResultTranslator
{
    public function translate(FiscalAdapterRequest $request, MeiAutomationAttempt $attempt): FiscalAdapterResult
    {
        $payload = $attempt->result_payload_encrypted;
        if (! is_array($payload)) {
            return FiscalAdapterResult::failed(
                'Resultado estruturado do portal não está disponível.',
                'PORTAL_RESULT_MISSING',
            );
        }

        $request->run->forceFill([
            'source_provenance' => $attempt->provider === MeiProvider::ReceitaPortal
                ? FiscalSourceProvenance::ReceitaPortal
                : FiscalSourceProvenance::Unverified,
        ])->save();

        return match ((string) $attempt->operation_key) {
            'pgmei.dividaativa' => $this->debt($payload),
            'pgmei.gerardaspdf', 'pgmei.gerardascodbarra' => $this->das($payload, $attempt),
            'dasnsimei.consultimadecrec' => $this->dasn($payload, $attempt),
            default => FiscalAdapterResult::failed(
                'Operação portal sem tradução fiscal.',
                'PORTAL_RESULT_UNSUPPORTED',
            ),
        };
    }

    /** @param array<string, mixed> $payload */
    private function debt(array $payload): FiscalAdapterResult
    {
        $years = is_array($payload['years'] ?? null) ? $payload['years'] : [];
        $hasDebt = collect($years)->contains(
            static fn (mixed $item): bool => is_array($item) && (int) ($item['debt_count'] ?? 0) > 0,
        );

        return new FiscalAdapterResult(
            result: FiscalRunResult::Success,
            situation: $hasDebt ? FiscalSituation::Pending : FiscalSituation::Unknown,
            coverage: FiscalCoverage::Partial,
            evidenceBytes: $this->evidence($payload),
            sourceVersion: (string) ($payload['parser_version'] ?? 'portal'),
            normalized: [
                'dto' => 'pgmei_portal_debt_summary',
                'coverage' => 'SUMMARY',
                'years' => $years,
                'payment_inferred' => false,
            ],
            findings: $hasDebt ? [[
                'code' => 'PGMEI_ACTIVE_DEBT_REPORTED',
                'severity' => FiscalFindingSeverity::High->value,
                'title' => 'Dívida ativa informada pelo PGMEI',
                'situation' => FiscalSituation::Pending->value,
                'creates_pending' => true,
            ]] : [],
            itemsProcessed: count($years),
            pagesProcessed: 1,
        );
    }

    /** @param array<string, mixed> $payload */
    private function das(array $payload, MeiAutomationAttempt $attempt): FiscalAdapterResult
    {
        return new FiscalAdapterResult(
            result: FiscalRunResult::Success,
            situation: FiscalSituation::Attention,
            coverage: FiscalCoverage::Full,
            evidenceBytes: $this->evidence([
                ...$payload,
                'artifact_count' => count($attempt->vault_artifacts ?? []),
            ]),
            sourceVersion: (string) ($payload['parser_version'] ?? 'portal'),
            normalized: [
                'dto' => 'pgmei_portal_das',
                'competencies' => $payload['competencies'] ?? [],
                'artifact_count' => count($attempt->vault_artifacts ?? []),
                'barcode_available' => ($payload['barcode_available'] ?? false) === true,
                'payment_inferred' => false,
            ],
            findings: [[
                'code' => 'DAS_MEI_EMITTED_PAYMENT_UNKNOWN',
                'severity' => FiscalFindingSeverity::Info->value,
                'title' => 'DAS MEI emitido (pagamento não confirmado)',
                'situation' => FiscalSituation::Attention->value,
                'creates_pending' => false,
            ]],
            itemsProcessed: count((array) ($payload['competencies'] ?? [])),
            pagesProcessed: 1,
        );
    }

    /** @param array<string, mixed> $payload */
    private function dasn(array $payload, MeiAutomationAttempt $attempt): FiscalAdapterResult
    {
        $declarations = is_array($payload['declarations'] ?? null)
            ? $payload['declarations']
            : [];
        $full = ($payload['coverage'] ?? null) === 'FULL';
        $pendingDeclarations = collect($declarations)->filter(static function (mixed $item): bool {
            if (is_array($item) && ($item['pending'] ?? false) === true) {
                return true;
            }
            $status = is_array($item) ? strtoupper((string) ($item['status'] ?? '')) : '';

            return in_array($status, ['PENDENTE', 'OMISSA', 'PENDING'], true);
        });
        $pending = $pendingDeclarations->isNotEmpty();
        $pendingYears = $pendingDeclarations
            ->map(static fn (array $item): mixed => $item['calendar_year'] ?? null)
            ->filter(static fn (mixed $year): bool => is_int($year))
            ->unique()
            ->sort()
            ->values()
            ->all();
        $artifactIds = collect($attempt->vault_artifacts ?? [])
            ->filter(static fn (mixed $artifact): bool => is_array($artifact)
                && is_string($artifact['id'] ?? null)
                && is_string($artifact['object_id'] ?? null)
                && $artifact['object_id'] !== '')
            ->pluck('id');
        $hasValidReceipts = $full && $declarations !== []
            && collect($declarations)->every(static fn (mixed $item): bool => is_array($item)
                && ($item['coverage'] ?? null) === 'FULL'
                && ($item['receipt_available'] ?? false) === true
                && is_string($item['receipt_artifact_id'] ?? null)
                && $artifactIds->contains($item['receipt_artifact_id']));
        $situation = $pending
            ? FiscalSituation::Pending
            : ($hasValidReceipts ? FiscalSituation::UpToDate : FiscalSituation::Unknown);

        return new FiscalAdapterResult(
            result: FiscalRunResult::Success,
            situation: $situation,
            coverage: $full ? FiscalCoverage::Full : FiscalCoverage::Partial,
            evidenceBytes: $this->evidence($payload),
            sourceVersion: (string) ($payload['parser_version'] ?? 'portal'),
            normalized: [
                'dto' => 'dasn_simei_portal_history',
                'coverage' => $payload['coverage'] ?? 'SUMMARY',
                'declarations' => $declarations,
                'pending_years' => $pendingYears,
            ],
            findings: $pending ? [[
                'code' => 'DASN_SIMEI_DECLARATION_PENDING',
                'severity' => FiscalFindingSeverity::High->value,
                'title' => 'Declaração anual DASN-SIMEI pendente',
                'detail' => $pendingYears === []
                    ? 'O portal informou ao menos uma declaração anual pendente.'
                    : 'Anos-calendário pendentes: '.implode(', ', $pendingYears).'.',
                'situation' => FiscalSituation::Pending->value,
                'creates_pending' => true,
            ]] : [],
            itemsProcessed: count($declarations),
            pagesProcessed: 1,
        );
    }

    /** @param array<string, mixed> $payload */
    private function evidence(array $payload): string
    {
        return (string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
