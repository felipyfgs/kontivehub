<?php

namespace App\Console\Commands;

use App\Contracts\SecureObjectStore;
use App\Enums\CaptureChannel;
use App\Enums\DocumentAcquisitionSource;
use App\Enums\DocumentArtifactQuality;
use App\Enums\DocumentDirection;
use App\Enums\FiscalRole;
use App\Models\CteDocument;
use App\Models\DocumentAcquisition;
use App\Models\DocumentInterest;
use App\Services\Sefaz\CteCoverageService;
use App\Services\Sefaz\CteXmlProjectionParser;
use App\Services\Vault\DocumentVaultReader;
use App\Support\LogSanitizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reprocessa projeções CT-e de forma idempotente:
 * - reparse via SecureObjectStore quando disponível
 * - reclassifica ISSUER/OUT indevido no DistDFe do cliente
 * - migra TAKER genérico → papel específico só com prova no XML
 * - backfill de origem/qualidade sem alterar SHA-256/bytes
 * - recalcula cobertura
 * Nunca apaga documentos canônicos.
 */
class CteReprocessProjectionsCommand extends Command
{
    protected $signature = 'cte:reprocess-projections
                            {--office= : Restringe a office_id}
                            {--dry-run : Apenas reporta impacto sem gravar}
                            {--limit=500 : Máximo de projeções por execução}';

    protected $description = 'Reprocessa projeções CT-e (idempotente; dry-run por padrão recomendado)';

    public function handle(
        SecureObjectStore $store,
        CteXmlProjectionParser $parser,
        CteCoverageService $coverage,
    ): int {
        $dry = (bool) $this->option('dry-run');
        $officeOpt = $this->option('office');
        $limit = max(1, min(5000, (int) $this->option('limit')));

        $counts = [
            'scanned' => 0,
            'reparsed' => 0,
            'issuer_out_removed' => 0,
            'taker_migrated' => 0,
            'taker_ambiguous' => 0,
            'acquisition_backfilled' => 0,
            'coverage_recomputed' => 0,
            'errors' => 0,
            'skipped_no_xml' => 0,
        ];

        $query = CteDocument::query()
            ->where('is_summary', false)
            ->with('document')
            ->orderBy('id');
        if ($officeOpt !== null && $officeOpt !== '') {
            $query->where('office_id', (int) $officeOpt);
        }

        $coveragePairs = [];

        $query->limit($limit)->get()->each(function (CteDocument $cte) use (
            $store,
            $parser,
            $dry,
            &$counts,
            &$coveragePairs,
        ): void {
            $counts['scanned']++;
            try {
                $this->processOne($cte, $store, $parser, $dry, $counts, $coveragePairs);
            } catch (Throwable $e) {
                $counts['errors']++;
                Log::warning('cte.reprocess.error', LogSanitizer::redact([
                    'cte_id' => $cte->id,
                    'office_id' => $cte->office_id,
                    'exception_class' => $e::class,
                    'exception_message' => $e->getMessage(),
                ]));
            }
        });

        if (! $dry) {
            foreach ($coveragePairs as $key => $true) {
                [$officeId, $clientId, $period] = explode(':', $key);
                $coverage->recompute((int) $officeId, (int) $clientId, $period);
                $counts['coverage_recomputed']++;
            }
        } else {
            $counts['coverage_recomputed'] = count($coveragePairs);
        }

        $prefix = $dry ? '[dry-run] ' : '';
        $this->info($prefix.'cte:reprocess-projections');
        foreach ($counts as $k => $v) {
            $this->line("  {$k}: {$v}");
        }

        Log::info('cte.reprocess.done', LogSanitizer::redact(array_merge($counts, [
            'dry_run' => $dry,
            'office' => $officeOpt,
        ])));

        return $counts['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array<string, int>  $counts
     * @param  array<string, true>  $coveragePairs
     */
    private function processOne(
        CteDocument $cte,
        SecureObjectStore $store,
        CteXmlProjectionParser $parser,
        bool $dry,
        array &$counts,
        array &$coveragePairs,
    ): void {
        $dfe = $cte->document;
        $parsed = null;

        if ($dfe !== null && $dfe->vault_object_id && $dfe->sha256) {
            try {
                $bytes = DocumentVaultReader::get(
                    $store,
                    (string) $dfe->vault_object_id,
                    (int) $cte->office_id,
                    (string) $dfe->sha256,
                );
                if ($bytes !== '') {
                    $parsed = $parser->parse($bytes, 'procCTe', null);
                    $counts['reparsed']++;
                }
            } catch (Throwable) {
                $counts['skipped_no_xml']++;
            }
        } else {
            $counts['skipped_no_xml']++;
        }

        // Reclassifica ISSUER/OUT no canal DistDFe do cliente (contrato: AN não distribui ao emitente)
        $badIssuerInterests = DocumentInterest::query()
            ->where('office_id', $cte->office_id)
            ->where('dfe_document_id', $cte->dfe_document_id)
            ->where('channel', CaptureChannel::CteDistDfe->value)
            ->where('fiscal_role', FiscalRole::Issuer->value)
            ->get();

        foreach ($badIssuerInterests as $interest) {
            $counts['issuer_out_removed']++;
            if (! $dry) {
                $interest->delete();
            }
        }

        // Migra TAKER legados → papel específico só se XML provar
        if ($parsed !== null) {
            $matched = $parsed['matched_roles'] ?? [];
            $takerInterests = DocumentInterest::query()
                ->where('office_id', $cte->office_id)
                ->where('dfe_document_id', $cte->dfe_document_id)
                ->where('fiscal_role', FiscalRole::Taker->value)
                ->get();

            foreach ($takerInterests as $interest) {
                $specific = array_values(array_filter(
                    $matched,
                    fn ($r) => $r instanceof FiscalRole
                        && $r !== FiscalRole::Taker
                        && $r->isCteClientInterest()
                ));
                if (count($specific) === 1) {
                    $counts['taker_migrated']++;
                    if (! $dry) {
                        $role = $specific[0];
                        $interest->fiscal_role = $role;
                        $interest->direction = DocumentDirection::fromFiscalRole($role);
                        $interest->save();
                    }
                } elseif ($specific === [] && ! in_array(FiscalRole::Taker, $matched, true)) {
                    $counts['taker_ambiguous']++;
                }
            }

            // Atualiza campos de projeção a partir do XML (sem tocar dfe_document/bytes)
            if (! $dry) {
                $cte->fill([
                    'issuer_cnpj' => $parsed['issuer_cnpj'] ?? $cte->issuer_cnpj,
                    'taker_cnpj' => $parsed['taker_cnpj'] ?? $cte->taker_cnpj,
                    'effective_taker_cnpj' => $parsed['effective_taker_cnpj'] ?? $cte->effective_taker_cnpj,
                    'sender_cnpj' => $parsed['sender_cnpj'] ?? $cte->sender_cnpj,
                    'recipient_cnpj' => $parsed['recipient_cnpj'] ?? $cte->recipient_cnpj,
                    'expeditor_cnpj' => $parsed['expeditor_cnpj'] ?? $cte->expeditor_cnpj,
                    'expeditor_name' => $parsed['expeditor_name'] ?? $cte->expeditor_name,
                    'receiver_cnpj' => $parsed['receiver_cnpj'] ?? $cte->receiver_cnpj,
                    'receiver_name' => $parsed['receiver_name'] ?? $cte->receiver_name,
                    'schema_version' => $parsed['schema_version'] ?? $cte->schema_version,
                ]);
                $cte->save();
            }
        }

        // Backfill origem/qualidade em aquisições sem qualidade
        $acqs = DocumentAcquisition::query()
            ->where('office_id', $cte->office_id)
            ->where('dfe_document_id', $cte->dfe_document_id)
            ->whereNull('artifact_quality')
            ->get();

        foreach ($acqs as $acq) {
            $counts['acquisition_backfilled']++;
            if (! $dry) {
                $src = $acq->source instanceof DocumentAcquisitionSource
                    ? $acq->source
                    : DocumentAcquisitionSource::tryFrom((string) $acq->source);
                $quality = match ($src) {
                    DocumentAcquisitionSource::CteAutXmlDistNsu => DocumentArtifactQuality::AutXmlOriginal,
                    default => DocumentArtifactQuality::Original,
                };
                // Não altera sha256/bytes
                $acq->artifact_quality = $quality;
                $acq->save();
            }
        }

        $clientIds = DocumentInterest::query()
            ->join('establishments', 'establishments.id', '=', 'document_interests.establishment_id')
            ->where('document_interests.office_id', $cte->office_id)
            ->where('document_interests.dfe_document_id', $cte->dfe_document_id)
            ->pluck('establishments.client_id')
            ->unique();
        $period = $cte->issued_at?->format('Y-m') ?? now()->format('Y-m');
        foreach ($clientIds as $clientId) {
            $coveragePairs[$cte->office_id.':'.$clientId.':'.$period] = true;
        }
    }
}
