<?php

namespace App\Jobs;

use App\Contracts\SecureObjectStore;
use App\DTO\Fiscal\Module\ModulePortfolioFilters;
use App\Enums\DocumentDirection;
use App\Enums\DocumentKind;
use App\Enums\FiscalDataOrigin;
use App\Enums\FiscalModuleKey;
use App\Enums\FiscalRole;
use App\Models\CteDocument;
use App\Models\Export;
use App\Models\NfeDocument;
use App\Models\NfseEvent;
use App\Models\NfseNote;
use App\Models\Office;
use App\Services\FiscalMonitoring\ModulePortfolio\ModulePortfolioQueryService;
use App\Services\Vault\DocumentVaultReader;
use App\Support\LogSanitizer;
use App\Support\NfseNoteStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;
use ZipArchive;

class BuildExportZipJob implements ShouldQueue
{
    use Queueable;

    /** Teto de chaves em exportação por seleção (catálogo / multi-select). */
    public const MAX_ACCESS_KEYS = 100;

    public function __construct(public int $exportId) {}

    public function handle(
        SecureObjectStore $store,
        ?ModulePortfolioQueryService $portfolio = null,
    ): void {
        $export = Export::query()->find($this->exportId);
        if ($export === null) {
            return;
        }

        $export->status = 'PROCESSING';
        $export->save();

        $dir = storage_path('app/private/exports/'.$export->office_id);
        File::ensureDirectoryExists($dir, 0700);
        $path = $dir.'/export-'.$export->id.'.zip';

        try {
            $zip = new ZipArchive;
            if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Não foi possível criar ZIP.');
            }

            $filters = $export->filters ?? [];

            if (($filters['export_scope'] ?? null) === 'fiscal_portfolio') {
                // Sempre gera manifesto + CSV/JSON (mesmo com 0 clientes no filtro).
                $portfolio ??= app(ModulePortfolioQueryService::class);
                $count = $this->addFiscalPortfolioEntries($zip, $export, $filters, $portfolio);
                $zip->close();
                $this->markReady($export, $path, $count);

                return;
            }

            $kinds = $this->resolveKinds($filters);
            $count = 0;
            $seen = [];
            /** @var list<array{kind: string, access_key: string, reason: string}> $skipped */
            $skipped = [];

            if ($kinds === [] || in_array(DocumentKind::Nfse, $kinds, true)) {
                $query = NfseNote::query()->where('office_id', $export->office_id)->with('document');
                $this->applySharedFilters($query, $filters, nfse: true);
                $query->orderBy('id')->chunkById(100, function ($notes) use ($store, $zip, &$count, &$seen, &$skipped, $export): void {
                    foreach ($notes as $note) {
                        if (isset($seen['NFSE:'.$note->access_key])) {
                            continue;
                        }
                        $seen['NFSE:'.$note->access_key] = true;
                        $doc = $note->document;
                        if ($doc === null) {
                            $skipped[] = ['kind' => 'nfse', 'access_key' => $note->access_key, 'reason' => 'sem_documento'];

                            continue;
                        }
                        $bytes = $this->readVaultXml($store, $doc->vault_object_id, (int) $doc->office_id, (string) $doc->sha256, $skipped, 'nfse', $note->access_key);
                        if ($bytes === null) {
                            continue;
                        }
                        $dirValue = $this->resolveDirectionValue($note->direction, $note->fiscal_role);
                        $entry = $this->zipEntryPath(
                            $dirValue,
                            'nfse',
                            $this->partyCnpjForPath($dirValue, $note->issuer_cnpj, $note->taker_cnpj),
                            $note->competence,
                            $note->access_key,
                        );
                        $zip->addFromString($entry, $bytes);
                        $count++;

                        if ($export->include_events) {
                            $events = NfseEvent::query()
                                ->where('office_id', $export->office_id)
                                ->where('access_key', $note->access_key)
                                ->with('document')
                                ->get();
                            foreach ($events as $i => $event) {
                                $edoc = $event->document;
                                if ($edoc === null) {
                                    continue;
                                }
                                $ebytes = $this->readVaultXml(
                                    $store,
                                    $edoc->vault_object_id,
                                    (int) $edoc->office_id,
                                    (string) $edoc->sha256,
                                    $skipped,
                                    'nfse-event',
                                    $note->access_key,
                                );
                                if ($ebytes === null) {
                                    continue;
                                }
                                $zip->addFromString(
                                    $this->zipEntryPath(
                                        $dirValue,
                                        'nfse',
                                        $this->partyCnpjForPath($dirValue, $note->issuer_cnpj, $note->taker_cnpj),
                                        $note->competence,
                                        $note->access_key,
                                        'event-'.($i + 1),
                                    ),
                                    $ebytes
                                );
                                $count++;
                            }
                        }
                    }
                });
            }

            if ($kinds === [] || in_array(DocumentKind::Nfe, $kinds, true) || in_array(DocumentKind::Nfce, $kinds, true)) {
                $query = NfeDocument::query()->where('office_id', $export->office_id)->with('document');
                // Prefer full over summary for same key
                $query->where(function (Builder $q): void {
                    $q->where('is_summary', false)
                        ->orWhereNotExists(function ($sub): void {
                            $sub->selectRaw('1')
                                ->from('nfe_documents as full')
                                ->whereColumn('full.office_id', 'nfe_documents.office_id')
                                ->whereColumn('full.access_key', 'nfe_documents.access_key')
                                ->where('full.is_summary', false);
                        });
                });
                if (in_array(DocumentKind::Nfe, $kinds, true) && ! in_array(DocumentKind::Nfce, $kinds, true) && $kinds !== []) {
                    $query->where(function ($q): void {
                        $q->where('model', '55')->orWhereNull('model');
                    });
                } elseif (in_array(DocumentKind::Nfce, $kinds, true) && ! in_array(DocumentKind::Nfe, $kinds, true) && $kinds !== []) {
                    $query->where('model', '65');
                }
                $this->applySharedFilters($query, $filters, nfse: false);
                $query->orderBy('id')->chunkById(100, function ($rows) use ($store, $zip, &$count, &$seen, &$skipped): void {
                    foreach ($rows as $row) {
                        $kindSeg = ($row->model === '65') ? 'nfce' : 'nfe';
                        $dedupe = strtoupper($kindSeg).':'.$row->access_key;
                        if (isset($seen[$dedupe])) {
                            continue;
                        }
                        $seen[$dedupe] = true;
                        $doc = $row->document;
                        if ($doc === null) {
                            $skipped[] = ['kind' => $kindSeg, 'access_key' => $row->access_key, 'reason' => 'sem_documento'];

                            continue;
                        }
                        $bytes = $this->readVaultXml(
                            $store,
                            $doc->vault_object_id,
                            (int) $doc->office_id,
                            (string) $doc->sha256,
                            $skipped,
                            $kindSeg,
                            $row->access_key,
                        );
                        if ($bytes === null) {
                            continue;
                        }
                        $comp = $row->issued_at?->format('Y-m') ?? 'sem-competencia';
                        $dirValue = $this->resolveDirectionValue($row->direction, $row->fiscal_role);
                        $entry = $this->zipEntryPath(
                            $dirValue,
                            $kindSeg,
                            $this->partyCnpjForPath(
                                $dirValue,
                                $row->issuer_cnpj,
                                $row->recipient_cnpj ?? null,
                            ),
                            $comp,
                            $row->access_key,
                            $row->is_summary ? 'resumo' : null,
                        );
                        $zip->addFromString($entry, $bytes);
                        $count++;
                    }
                });
            }

            if ($kinds === [] || in_array(DocumentKind::Cte, $kinds, true)) {
                $query = CteDocument::query()->where('office_id', $export->office_id)->with('document');
                // Prefer full over summary for same access_key (espelho NF-e).
                $query->where(function (Builder $q): void {
                    $q->where('is_summary', false)
                        ->orWhereNotExists(function ($sub): void {
                            $sub->selectRaw('1')
                                ->from('cte_documents as full')
                                ->whereColumn('full.office_id', 'cte_documents.office_id')
                                ->whereColumn('full.access_key', 'cte_documents.access_key')
                                ->where('full.is_summary', false);
                        });
                });
                $this->applySharedFilters($query, $filters, nfse: false);
                $query->orderBy('id')->chunkById(100, function ($rows) use ($store, $zip, &$count, &$seen, &$skipped): void {
                    foreach ($rows as $row) {
                        if (isset($seen['CTE:'.$row->access_key])) {
                            continue;
                        }
                        $seen['CTE:'.$row->access_key] = true;
                        $doc = $row->document;
                        if ($doc === null) {
                            $skipped[] = ['kind' => 'cte', 'access_key' => $row->access_key, 'reason' => 'sem_documento'];

                            continue;
                        }
                        $bytes = $this->readVaultXml(
                            $store,
                            $doc->vault_object_id,
                            (int) $doc->office_id,
                            (string) $doc->sha256,
                            $skipped,
                            'cte',
                            $row->access_key,
                        );
                        if ($bytes === null) {
                            continue;
                        }
                        $comp = $row->issued_at?->format('Y-m') ?? 'sem-competencia';
                        $dirValue = $this->resolveDirectionValue($row->direction, $row->fiscal_role);
                        $entry = $this->zipEntryPath(
                            $dirValue,
                            'cte',
                            $this->partyCnpjForPath(
                                $dirValue,
                                $row->issuer_cnpj,
                                $row->taker_cnpj ?? $row->recipient_cnpj ?? null,
                            ),
                            $comp,
                            $row->access_key,
                            $row->is_summary ? 'resumo' : null,
                        );
                        $zip->addFromString($entry, $bytes);
                        $count++;
                    }
                });
            }

            // MDF-e legado nunca entra nos ramos de consulta ou no vault.

            // Manifesto de ausências (exportação mensal parcial) — nunca inventa XML.
            $manifestPath = is_string($filters['absence_manifest_path'] ?? null)
                ? (string) $filters['absence_manifest_path']
                : null;
            if ($manifestPath !== null && $manifestPath !== '' && is_file($manifestPath)) {
                $root = realpath(storage_path('app/private/exports/'.$export->office_id));
                $real = realpath($manifestPath);
                if ($root !== false && $real !== false
                    && (str_starts_with($real, $root.DIRECTORY_SEPARATOR) || $real === $root)) {
                    $zip->addFromString(
                        'manifesto-ausencias-'.$this->safeSegment((string) ($filters['competence'] ?? 'competencia')).'.json',
                        (string) file_get_contents($real)
                    );
                    $count++;
                }
            }

            // Relatório de itens sem XML legível (não substitui o XML).
            if ($skipped !== []) {
                $zip->addFromString(
                    'export-skipped.json',
                    (string) json_encode([
                        'skipped_count' => count($skipped),
                        'items' => $skipped,
                    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
                );
            }

            $zip->close();

            if ($count === 0) {
                if (is_file($path)) {
                    @unlink($path);
                }
                $export->status = 'FAILED';
                $export->error_message = $skipped !== []
                    ? 'Nenhum XML legível no cofre para os filtros (objetos inacessíveis ou ausentes).'
                    : 'Nenhum documento encontrado para os filtros informados.';
                $export->storage_path = null;
                $export->files_count = 0;
                $export->save();

                return;
            }

            $this->markReady($export, $path, $count);
        } catch (Throwable $e) {
            if (is_file($path)) {
                @unlink($path);
            }
            $export->status = 'FAILED';
            $export->error_message = 'Falha ao gerar exportação.';
            $export->storage_path = null;
            $export->save();
            report($e);
        }
    }

    /**
     * Carteira fiscal sanitizada: CSV + JSON + manifesto (marcação DEMO/SIMULATED).
     *
     * @param  array<string, mixed>  $filters
     */
    private function addFiscalPortfolioEntries(
        ZipArchive $zip,
        Export $export,
        array $filters,
        ModulePortfolioQueryService $portfolio,
    ): int {
        $module = FiscalModuleKey::tryFromRoute((string) ($filters['module_key'] ?? ''));
        if ($module === null || $module === FiscalModuleKey::Dashboard) {
            throw new \RuntimeException('Módulo fiscal inválido no export.');
        }

        $office = Office::query()->find($export->office_id);
        if ($office === null) {
            throw new \RuntimeException('Escritório do export não encontrado.');
        }

        $portfolioFilters = ModulePortfolioFilters::fromRequest([
            'page' => 1,
            'per_page' => 100,
            'q' => $filters['q'] ?? null,
            'situation' => $filters['situation'] ?? null,
            'competence' => $filters['competence'] ?? null,
            'submodule' => $filters['submodule'] ?? null,
            'client_id' => $filters['client_id'] ?? null,
            'coverage' => $filters['coverage'] ?? null,
            'modality' => $filters['modality'] ?? null,
            'delivery_status' => $filters['delivery_status'] ?? null,
        ]);

        $payload = $portfolio->exportSanitizedRows($office, $module, $portfolioFilters);
        /** @var FiscalDataOrigin $origin */
        $origin = $payload['data_origin'];
        $isDemo = (bool) $payload['is_demonstration'];
        /** @var list<array<string, mixed>> $rows */
        $rows = $payload['rows'];

        $manifest = [
            'export_id' => $export->id,
            'export_scope' => 'fiscal_portfolio',
            'module_key' => $module->value,
            'module_label' => $module->label(),
            'filters' => [
                'module_key' => $module->value,
                'situation' => $portfolioFilters->situation,
                'competence' => $portfolioFilters->competence,
                'q' => $portfolioFilters->q,
                'submodule' => $portfolioFilters->submodule,
                'client_id' => $portfolioFilters->clientId,
            ],
            'data_origin' => $origin->value,
            'data_origin_label' => $origin->label(),
            'is_demonstration' => $isDemo,
            'demonstration_banner' => $isDemo
                ? 'DEMONSTRAÇÃO — dados sem validade fiscal; não utilizar para obrigações reais.'
                : null,
            'row_count' => count($rows),
            'total_in_scope' => $payload['total'],
            'generated_at' => now()->toIso8601String(),
            'sanitization' => [
                'cnpj_masked' => true,
                'no_secrets' => true,
                'no_xml' => true,
                'no_vault_ids' => true,
            ],
            // office_id NÃO é exposto no manifesto público do ZIP além do path de storage
            // (já isolado). Identidade comercial do tenant fica fora do artefato.
        ];

        $count = 0;

        $zip->addFromString(
            'manifest.json',
            (string) json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
        $count++;

        $zip->addFromString(
            'portfolio.json',
            (string) json_encode([
                'manifest' => [
                    'module_key' => $module->value,
                    'data_origin' => $origin->value,
                    'is_demonstration' => $isDemo,
                    'row_count' => count($rows),
                ],
                'data' => $rows,
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
        $count++;

        $zip->addFromString('portfolio.csv', $this->buildPortfolioCsv($rows));
        $count++;

        if ($isDemo) {
            $zip->addFromString(
                'DEMONSTRACAO.txt',
                "DEMONSTRAÇÃO / DADOS SINTÉTICOS\n"
                ."Origem: {$origin->value} ({$origin->label()})\n"
                ."Este arquivo NÃO possui validade fiscal.\n"
                ."Não utilize para entrega, recolhimento ou prova junto a órgãos públicos.\n"
            );
            $count++;
        }

        return $count;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function buildPortfolioCsv(array $rows): string
    {
        $headers = [
            'module_key',
            'client_id',
            'legal_name',
            'display_name',
            'cnpj_masked',
            'root_cnpj_masked',
            'competence',
            'situation',
            'coverage',
            'data_origin',
            'last_consulted_at',
            'next_deadline_at',
            'next_action',
        ];

        $fh = fopen('php://temp', 'r+');
        if ($fh === false) {
            throw new \RuntimeException('Falha ao abrir buffer CSV.');
        }

        fputcsv($fh, $headers, ',', '"', '\\');
        foreach ($rows as $row) {
            $line = [];
            foreach ($headers as $h) {
                $val = $row[$h] ?? '';
                $line[] = is_scalar($val) || $val === null ? (string) ($val ?? '') : '';
            }
            fputcsv($fh, $line, ',', '"', '\\');
        }
        rewind($fh);
        $csv = stream_get_contents($fh) ?: '';
        fclose($fh);

        return $csv;
    }

    private function markReady(Export $export, string $path, int $count): void
    {
        $export->status = 'READY';
        $export->storage_path = $path;
        $export->byte_size = filesize($path) ?: 0;
        $export->files_count = $count;
        $export->expires_at = now()->addHours(24);
        $export->completed_at = now();
        $export->save();
    }

    /**
     * Lê XML do vault; em falha registra skip e não aborta o ZIP inteiro.
     *
     * @param  list<array{kind: string, access_key: string, reason: string}>  $skipped
     */
    private function readVaultXml(
        SecureObjectStore $store,
        ?string $objectId,
        int $officeId,
        string $sha256,
        array &$skipped,
        string $kind,
        string $accessKey,
    ): ?string {
        if ($objectId === null || $objectId === '' || $sha256 === '') {
            $skipped[] = ['kind' => $kind, 'access_key' => $accessKey, 'reason' => 'sem_vault'];

            return null;
        }

        try {
            $bytes = DocumentVaultReader::get($store, $objectId, $officeId, $sha256);
            // Nunca embutir envelope JSON do cofre no ZIP — só XML/texto fiscal.
            $trim = ltrim($bytes);
            if ($trim !== '' && ($trim[0] === '{' || $trim[0] === '[')) {
                $skipped[] = ['kind' => $kind, 'access_key' => $accessKey, 'reason' => 'conteudo_nao_xml'];
                // Nunca logar access_key completa — só prefixo; contexto via LogSanitizer.
                Log::warning('export.vault_not_xml', LogSanitizer::redact([
                    'kind' => $kind,
                    'key_hint' => $this->maskAccessKeyForLog($accessKey),
                ]));

                return null;
            }

            return $bytes;
        } catch (Throwable $e) {
            $skipped[] = ['kind' => $kind, 'access_key' => $accessKey, 'reason' => 'vault_inacessivel'];
            Log::warning('export.vault_read_failed', LogSanitizer::redact([
                'kind' => $kind,
                'key_hint' => $this->maskAccessKeyForLog($accessKey),
                'message' => LogSanitizer::scrubString($e->getMessage()),
            ]));

            return null;
        }
    }

    /** Prefixo seguro para logs (nunca chave completa de 44 dígitos). */
    private function maskAccessKeyForLog(string $accessKey): string
    {
        $key = strtoupper(preg_replace('/\s+/', '', $accessKey) ?? '');
        if ($key === '') {
            return '';
        }

        return mb_substr($key, 0, 8).'…';
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<DocumentKind>
     */
    private function resolveKinds(array $filters): array
    {
        $raw = $filters['kind'] ?? $filters['kinds'] ?? null;
        if ($raw === null || $raw === '' || $raw === 'all') {
            return [];
        }
        $values = is_array($raw) ? $raw : [$raw];
        $out = [];
        foreach ($values as $v) {
            $kind = DocumentKind::tryFromRequest(is_string($v) ? $v : null);
            if ($kind !== null) {
                $out[$kind->value] = $kind;
            }
        }

        return array_values($out);
    }

    /**
     * @param  Builder<Model>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applySharedFilters(Builder $query, array $filters, bool $nfse): void
    {
        if ($nfse && ! empty($filters['competence'])) {
            $query->where('competence', $filters['competence']);
        }
        $accessKeys = $filters['access_keys'] ?? null;
        if (is_array($accessKeys) && $accessKeys !== []) {
            $keys = array_values(array_unique(array_filter(array_map(
                static fn ($k) => is_string($k) ? trim($k) : '',
                $accessKeys,
            ))));
            $query->whereIn('access_key', $keys);
        } elseif (! empty($filters['access_key'])) {
            $query->where('access_key', $filters['access_key']);
        }
        if (! empty($filters['issuer_cnpj'])) {
            $query->where('issuer_cnpj', $this->normalizeIdentifier($filters['issuer_cnpj']));
        }
        if ($nfse && ! empty($filters['taker_cnpj'])) {
            $query->where('taker_cnpj', $this->normalizeIdentifier($filters['taker_cnpj']));
        }
        if (! empty($filters['fiscal_role'])) {
            $query->where('fiscal_role', $filters['fiscal_role']);
        }
        if (! empty($filters['direction'])) {
            $query->where('direction', strtoupper((string) $filters['direction']));
        }
        if ($nfse && ! empty($filters['status'])) {
            $statuses = NfseNoteStatus::statusesForFilter((string) $filters['status']);
            if (count($statuses) === 1) {
                $query->where('status', $statuses[0]);
            } elseif (count($statuses) > 1) {
                $query->whereIn('status', $statuses);
            }
        } elseif (! $nfse && ! empty($filters['status'])) {
            $query->where('status', strtoupper((string) $filters['status']));
        }
        if (! empty($filters['issued_from'])) {
            $query->whereDate('issued_at', '>=', $filters['issued_from']);
        }
        if (! empty($filters['issued_to'])) {
            $query->whereDate('issued_at', '<=', $filters['issued_to']);
        }
        if (! empty($filters['client_id'])) {
            $clientId = (int) $filters['client_id'];
            $query->whereHas('document.interests.establishment', function ($q) use ($clientId): void {
                $q->where('client_id', $clientId);
            });
        }
        if (! empty($filters['establishment_id'])) {
            $establishmentId = (int) $filters['establishment_id'];
            $query->whereHas('document.interests', function ($q) use ($establishmentId): void {
                $q->where('establishment_id', $establishmentId);
            });
        }
    }

    /**
     * Direção efetiva no ZIP: usa a coluna direction; se nula, deriva do papel fiscal.
     */
    private function resolveDirectionValue(mixed $direction, mixed $fiscalRole): ?string
    {
        if ($direction instanceof DocumentDirection) {
            return $direction === DocumentDirection::Unknown ? null : $direction->value;
        }
        if (is_string($direction) && $direction !== '' && strtoupper($direction) !== 'UNKNOWN') {
            return strtoupper($direction);
        }

        $role = $fiscalRole instanceof FiscalRole
            ? $fiscalRole
            : (is_string($fiscalRole) ? FiscalRole::tryFrom($fiscalRole) : null);
        $derived = DocumentDirection::fromFiscalRole($role);

        return $derived === DocumentDirection::Unknown ? null : $derived->value;
    }

    /**
     * Layout do ZIP (padrão operacional):
     * {entrada|saida}/{nfse|nfe|nfce|cte}/{cnpj}/{YYYYMM}/{chave}.xml
     *
     * Sem pasta de papel (ISSUER/TAKER) — recorte por CNPJ + chave basta.
     * Competência no caminho: 202607 (não 2026-07).
     */
    private function zipEntryPath(
        ?string $directionValue,
        string $kind,
        ?string $cnpj,
        ?string $competence,
        string $accessKey,
        ?string $suffix = null,
    ): string {
        $direction = match ($directionValue) {
            'OUT' => 'saida',
            'IN' => 'entrada',
            default => 'indefinida',
        };
        $comp = $this->competenceFolder($competence);
        $fileBase = $this->safeSegment($accessKey);
        if ($suffix) {
            $fileBase .= '-'.$this->safeSegment($suffix);
        }

        return sprintf(
            '%s/%s/%s/%s/%s.xml',
            $direction,
            $kind,
            $this->safeSegment($this->normalizeIdentifier($cnpj ?: '') ?: 'sem-cnpj'),
            $comp,
            $fileBase,
        );
    }

    /**
     * Pasta de competência no ZIP: YYYYMM.
     */
    private function competenceFolder(?string $competence): string
    {
        $raw = trim((string) $competence);
        if (preg_match('/^(\d{4})-(\d{2})$/', $raw, $m)) {
            return $m[1].$m[2];
        }
        if (preg_match('/^\d{6}$/', $raw)) {
            return $raw;
        }
        if (preg_match('/^(\d{4})(\d{2})/', preg_replace('/\D/', '', $raw) ?? '', $m) && strlen(preg_replace('/\D/', '', $raw) ?? '') >= 6) {
            return substr(preg_replace('/\D/', '', $raw) ?? '', 0, 6);
        }

        return 'sem-competencia';
    }

    /**
     * CNPJ da pasta: na saída o emitente/prestador; na entrada o destinatário/tomador
     * (CNPJ do interessado do escritório no documento).
     */
    private function partyCnpjForPath(?string $directionValue, ?string $issuerCnpj, ?string $takerOrRecipientCnpj): string
    {
        if ($directionValue === 'IN') {
            return $this->normalizeIdentifier((string) ($takerOrRecipientCnpj ?: $issuerCnpj ?: ''));
        }

        return $this->normalizeIdentifier((string) ($issuerCnpj ?: $takerOrRecipientCnpj ?: ''));
    }

    private function normalizeIdentifier(string $value): string
    {
        return strtoupper(preg_replace('/[^A-Z0-9]/i', '', $value) ?? '');
    }

    private function safeSegment(string $value): string
    {
        $safe = preg_replace('/[^A-Z0-9_-]/i', '-', $value) ?? '';

        return trim($safe, '.-') ?: 'desconhecido';
    }
}
