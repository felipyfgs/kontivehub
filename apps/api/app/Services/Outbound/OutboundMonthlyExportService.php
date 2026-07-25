<?php

namespace App\Services\Outbound;

use App\Enums\OutboundFiscalModel;
use App\Enums\OutboundMonthlyReadinessStatus;
use App\Enums\OutboundRetrievalOrigin;
use App\Enums\OutboundUrgencyBand;
use App\Enums\SvrsNfceRecoveryStatus;
use App\Jobs\BuildExportZipJob;
use App\Models\Export;
use App\Models\MaOutboundRetrievalRequest;
use App\Models\OutboundMonthlyReadiness;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use RuntimeException;

/**
 * Exportação mensal de saídas com prontidão explícita e manifesto de ausências.
 * ZIP/manifesto ficam sob storage privado por office_id; não inventa XML.
 */
final class OutboundMonthlyExportService
{
    public function __construct(
        private readonly OutboundMonthlyReadinessService $readiness,
        private readonly OutboundDeadlineSatisfactionService $satisfaction,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{export: Export, readiness: OutboundMonthlyReadiness, manifest_path: ?string}
     */
    public function createMonthlyExport(
        int $officeId,
        int $userId,
        string $competence,
        bool $includeEvents = false,
        ?string $notes = null,
    ): array {
        $ready = $this->readiness->refresh($officeId, $competence);
        $status = $ready->status;

        if ($status === OutboundMonthlyReadinessStatus::NotReady) {
            throw new InvalidArgumentException(
                'Competência NOT_READY: confirme exportação parcial (OPERATOR/ADMIN) ou aguarde COMPLETE_KNOWN.'
            );
        }

        if ($status === OutboundMonthlyReadinessStatus::PartialConfirmed && $ready->pending_total > 0) {
            // partial já confirmado — manifesto será embutido no ZIP
        }

        $manifest = $this->buildAbsenceManifest($officeId, $competence, $ready);
        $manifestPath = $this->persistManifestFile($officeId, $competence, $manifest);

        $export = Export::query()->create([
            'office_id' => $officeId,
            'user_id' => $userId,
            'status' => 'PENDING',
            'filters' => [
                'competence' => $competence,
                'direction' => 'OUT',
                'kind' => ['nfe', 'nfce'],
                'monthly_export' => true,
                'readiness_status' => $status->value,
                'absence_manifest_path' => $manifestPath,
            ],
            'include_events' => $includeEvents,
        ]);

        $ready->forceFill([
            'export_id' => $export->id,
            'confirmation_notes' => $notes !== null
                ? mb_substr($notes, 0, 1000)
                : $ready->confirmation_notes,
        ])->save();

        BuildExportZipJob::dispatch($export->id);

        $this->audit->record('outbound.monthly.export.create', 'SUCCESS', $export, [
            'competence' => $competence,
            'readiness_status' => $status->value,
            'known_total' => $ready->known_total,
            'pending_total' => $ready->pending_total,
            'has_manifest' => $manifestPath !== null,
            'completeness_scope' => 'known_documents_only',
        ], $userId, $officeId);

        return [
            'export' => $export,
            'readiness' => $ready->fresh(),
            'manifest_path' => $manifestPath,
        ];
    }

    /**
     * @return array{
     *   competence: string,
     *   office_id: int,
     *   readiness_status: string,
     *   completeness_scope: string,
     *   generated_at: string,
     *   known_total: int,
     *   captured_total: int,
     *   pending_total: int,
     *   absences: list<array<string, mixed>>
     * }
     */
    public function buildAbsenceManifest(int $officeId, string $competence, ?OutboundMonthlyReadiness $ready = null): array
    {
        $ready ??= $this->readiness->refresh($officeId, $competence);
        $batch = $this->satisfaction->contingencyBatch($officeId, $competence);

        // Inclui pendências PLANNED/ATTENTION que não estão no lote de contingência
        $pending = MaOutboundRetrievalRequest::query()
            ->where('office_id', $officeId)
            ->where('origin', OutboundRetrievalOrigin::SvrsPortalByKey)
            ->where('competence', $competence)
            ->whereNotIn('recovery_status', [
                SvrsNfceRecoveryStatus::Captured->value,
                SvrsNfceRecoveryStatus::ResolvedByOtherSource->value,
            ])
            ->whereNotIn('urgency_band', [OutboundUrgencyBand::Captured->value])
            ->orderBy('due_at')
            ->get();

        $absences = [];
        $seen = [];
        foreach ($pending as $row) {
            $key = (string) $row->access_key;
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $absences[] = [
                'access_key_masked' => $this->maskKey($key),
                'root_cnpj_masked' => $row->root_cnpj
                    ? substr((string) $row->root_cnpj, 0, 4).'****'.substr((string) $row->root_cnpj, -2)
                    : null,
                'model' => $row->model instanceof OutboundFiscalModel
                    ? $row->model->value
                    : $row->model,
                'urgency_band' => $row->urgency_band?->value,
                'recovery_status' => $row->recovery_status?->value,
                'due_at' => $row->due_at?->toIso8601String(),
                'target_at' => $row->target_at?->toIso8601String(),
                'recommended_action' => match ($row->urgency_band) {
                    OutboundUrgencyBand::Contingency, OutboundUrgencyBand::Overdue => 'ASSISTED_IMPORT_OR_PACKAGE',
                    OutboundUrgencyBand::Attention => 'PREPARE_ASSISTED_BATCH',
                    default => 'WAIT_OR_PREFER_AUTXML',
                },
            ];
        }

        // Se contingency batch trouxe itens mas recovery query vazia (edge), merge mascarados
        if ($absences === [] && $batch !== []) {
            foreach ($batch as $item) {
                $absences[] = [
                    'access_key_masked' => $item['access_key_masked'] ?? null,
                    'root_cnpj_masked' => $item['root_cnpj_masked'] ?? null,
                    'model' => $item['model'] ?? null,
                    'urgency_band' => $item['urgency_band'] ?? null,
                    'recovery_status' => $item['recovery_status'] ?? null,
                    'due_at' => $item['due_at'] ?? null,
                    'target_at' => $item['target_at'] ?? null,
                    'recommended_action' => $item['recommended_action'] ?? 'ASSISTED_IMPORT_OR_PACKAGE',
                ];
            }
        }

        return [
            'competence' => $competence,
            'office_id' => $officeId,
            'readiness_status' => $ready->status->value,
            'completeness_scope' => 'known_documents_only',
            'sla_note' => 'SLA operacional interno — não é prazo legal nem universo fiscal absoluto.',
            'generated_at' => now()->toIso8601String(),
            'known_total' => $ready->known_total,
            'captured_total' => $ready->captured_total,
            'pending_total' => $ready->pending_total,
            'absences' => $absences,
        ];
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    public function persistManifestFile(int $officeId, string $competence, array $manifest): ?string
    {
        if (($manifest['pending_total'] ?? 0) <= 0 && ($manifest['absences'] ?? []) === []) {
            return null;
        }

        $dir = storage_path('app/private/exports/'.$officeId.'/manifests');
        File::ensureDirectoryExists($dir, 0700);
        $safeComp = preg_replace('/[^0-9-]/', '', $competence) ?: 'unknown';
        $path = $dir.'/manifest-'.$safeComp.'-'.now()->format('YmdHis').'.json';
        $json = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if (file_put_contents($path, $json, LOCK_EX) === false) {
            throw new RuntimeException('Falha ao gravar manifesto de ausências.');
        }
        @chmod($path, 0600);

        return $path;
    }

    /**
     * Remove manifesto temporário associado a export expirado (mesmo office).
     */
    public function purgeManifestIfOwned(int $officeId, ?string $path): void
    {
        if ($path === null || $path === '') {
            return;
        }
        $root = realpath(storage_path('app/private/exports/'.$officeId));
        $real = realpath($path);
        if ($root === false || $real === false) {
            return;
        }
        if (! str_starts_with($real, $root.DIRECTORY_SEPARATOR) && $real !== $root) {
            return;
        }
        if (is_file($real)) {
            @unlink($real);
        }
    }

    private function maskKey(?string $key): ?string
    {
        if ($key === null || strlen($key) < 12) {
            return $key;
        }

        return substr($key, 0, 6).str_repeat('*', max(0, strlen($key) - 10)).substr($key, -4);
    }
}
