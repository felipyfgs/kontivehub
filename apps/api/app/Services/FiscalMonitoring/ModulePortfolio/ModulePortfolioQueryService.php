<?php

namespace App\Services\FiscalMonitoring\ModulePortfolio;

use App\Domain\Cnpj;
use App\DTO\Fiscal\FiscalDocumentDescriptorDto;
use App\DTO\Fiscal\Module\ModuleAgendaItemDto;
use App\DTO\Fiscal\Module\ModuleCategorySummaryDto;
use App\DTO\Fiscal\Module\ModuleClientRowDto;
use App\DTO\Fiscal\Module\ModuleCountersDto;
use App\DTO\Fiscal\Module\ModuleOverviewDto;
use App\DTO\Fiscal\Module\ModulePortfolioFilters;
use App\Enums\FiscalCoverage;
use App\Enums\FiscalDataOrigin;
use App\Enums\FiscalLinkStatus;
use App\Enums\FiscalModuleKey;
use App\Enums\FiscalSituation;
use App\Enums\TaxInstallmentModality;
use App\Enums\TaxInstallmentParcelStatus;
use App\Enums\TaxRegimeCode;
use App\Models\Client;
use App\Models\ClientProcuracaoSync;
use App\Models\FgtsCompetenceStatus;
use App\Models\FiscalCategory;
use App\Models\FiscalCompetence;
use App\Models\FiscalEvidenceArtifact;
use App\Models\FiscalFinding;
use App\Models\FiscalPendingItem;
use App\Models\FiscalSnapshot;
use App\Models\MailboxContributorState;
use App\Models\MailboxMessage;
use App\Models\MitAssessment;
use App\Models\TaxGuide;
use App\Models\TaxInstallmentOrder;
use App\Models\TaxInstallmentParcel;
use App\Models\TaxObligationProjection;
use App\Models\Tenant;
use App\Models\TenantFiscalCategoryLink;
use App\Services\Fiscal\Dctfweb\DctfwebMonitoringQueryService;
use App\Services\Fiscal\SimplesMei\Pgdasd\PgdasdCommunicationService;
use App\Services\Fiscal\SimplesMei\Pgdasd\PgdasdMonitoringQueryService;
use App\Services\Fiscal\SimplesMei\Pgdasd\PgdasdPeriod;
use App\Services\Fiscal\SimplesMei\Pgmei\PgmeiMonitoringQueryService;
use App\Services\Fiscal\Sitfis\SitfisCommunicationService;
use App\Services\FiscalMonitoring\FiscalCoverageAggregator;
use App\Services\FiscalMonitoring\FiscalDocumentDescriptorFactory;
use App\Services\FiscalMonitoring\MonitoringModuleMembershipService;
use App\Services\FiscalMonitoring\Surfaces\MonitoringSurfaceContract;
use App\Services\FiscalMonitoring\Surfaces\MonitoringSurfaceRegistry;
use App\Services\Integra\ClientProcuracaoValidityResolver;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator as ConcretePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read model tenant-scoped de overview + carteira por módulo.
 * Contadores e lista compartilham o mesmo escopo normalizado (filtros SQL).
 */
final class ModulePortfolioQueryService
{
    public function __construct(
        private readonly DataOriginResolver $dataOrigin,
        private readonly MonitoringSurfaceRegistry $surfaces,
        private readonly FiscalDocumentDescriptorFactory $documentDescriptors,
        private readonly FiscalCoverageAggregator $coverageAggregator,
        private readonly MonitoringModuleMembershipService $membership,
    ) {}

    public function overview(
        Tenant $tenant,
        FiscalModuleKey $module,
        ModulePortfolioFilters $filters,
    ): ModuleOverviewDto {
        $origin = $this->dataOrigin->resolve($tenant);
        $categories = $this->moduleCategories($module);
        $surface = $this->surfaces->resolveForModule($module, $filters->submodule);

        // Contadores + total no mesmo agrupamento (sem filtro de situation).
        $counters = $this->aggregateCounters($tenant, $module, $filters);
        $total = $counters->total();
        $agenda = $this->buildAgenda($tenant, $module, $filters);
        $categorySummaries = $this->categorySummaries($tenant, $categories, $filters);
        $asOf = $this->latestObservedAt($tenant, $module, $filters);
        $coverage = $this->moduleDefaultCoverage($categories);
        if ($module === FiscalModuleKey::Fgts
            && $coverage === FiscalCoverage::Full->value
        ) {
            $coverage = FiscalCoverage::Partial->value;
        }
        if ($module === FiscalModuleKey::Declarations
            && strtoupper((string) ($filters->submodule ?? '')) === 'DIRF'
        ) {
            $coverage = FiscalCoverage::Unsupported->value;
        }

        return new ModuleOverviewDto(
            moduleKey: $module,
            dataOrigin: $origin,
            coverage: $coverage,
            sourceLabel: $module->label(),
            asOf: $asOf,
            totalClients: $total,
            counters: $counters,
            agenda: $agenda,
            categories: $categorySummaries,
            metrics: $this->moduleMetrics($tenant, $module, $filters, $total),
            surface: $surface->toPublicArray(),
        );
    }

    /**
     * @return LengthAwarePaginator<int, ModuleClientRowDto>
     */
    public function clients(
        Tenant $tenant,
        FiscalModuleKey $module,
        ModulePortfolioFilters $filters,
    ): LengthAwarePaginator {
        $origin = $this->dataOrigin->resolve($tenant);
        $idsQuery = $this->scopedClientIdsQuery($tenant, $module, $filters);
        $isPgdasdPortfolio = $this->isPgdasdPortfolioSubmodule($module, $filters);

        $sortColumn = match ($filters->sort) {
            'display_name' => 'clients.display_name',
            'id' => 'clients.id',
            'situation' => $isPgdasdPortfolio
                ? 'portfolio_pgdasd_declaration_state'
                : 'portfolio_situation',
            'last_declaration' => $isPgdasdPortfolio
                ? 'portfolio_pgdasd_last_declaration'
                : 'clients.legal_name',
            'rbt12' => $isPgdasdPortfolio
                ? 'portfolio_pgdasd_rbt12'
                : 'clients.legal_name',
            'last_consulted_at' => 'portfolio_last_consulted_at',
            'competence' => 'portfolio_competence',
            default => 'clients.legal_name',
        };

        $pageQuery = Client::query()
            ->withoutGlobalScopes()
            ->from('clients')
            ->whereIn('clients.id', $idsQuery)
            ->where('clients.tenant_id', $tenant->id)
            ->select('clients.*')
            ->selectSub(
                $this->situationSubquery($tenant, $module, $filters),
                'portfolio_situation',
            )
            ->selectSub(
                $this->lastConsultedSubquery($tenant, $module, $filters),
                'portfolio_last_consulted_at',
            )
            ->selectSub(
                $this->competenceSubquery($tenant, $module, $filters),
                'portfolio_competence',
            );

        if ($isPgdasdPortfolio) {
            $pageQuery
                ->selectSub(
                    $this->pgdasdDeclarationStateSortSubquery($tenant),
                    'portfolio_pgdasd_declaration_state',
                )
                ->selectSub(
                    $this->pgdasdLastDeclarationSortSubquery($tenant),
                    'portfolio_pgdasd_last_declaration',
                )
                ->selectSub(
                    $this->pgdasdRbt12SortSubquery($tenant),
                    'portfolio_pgdasd_rbt12',
                );
        }

        $pageQuery
            ->orderBy($sortColumn, $filters->sortDirection)
            ->orderBy('clients.id');

        /** @var LengthAwarePaginator<int, Client> $paginator */
        $paginator = $pageQuery->paginate($filters->perPage, ['*'], 'page', $filters->page);

        $clients = $paginator->getCollection();
        $clientIds = $clients->pluck('id')->map(fn ($id) => (int) $id)->all();

        $matrixCnpjs = $this->loadMatrixCnpjs($tenant, $clientIds);
        $coverages = $this->loadCoverages($tenant, $module, $clientIds, $filters);
        $details = $this->loadModuleDetails($tenant, $module, $clientIds, $filters);
        $deadlines = $this->loadNextDeadlines($tenant, $module, $clientIds, $filters);
        $documents = $this->loadDocuments($tenant, $module, $filters, $clientIds);

        $rows = $clients->map(function (Client $client) use (
            $module,
            $origin,
            $matrixCnpjs,
            $coverages,
            $details,
            $deadlines,
            $documents,
        ): ModuleClientRowDto {
            $id = (int) $client->id;
            $situation = (string) ($client->getAttribute('portfolio_situation') ?? FiscalSituation::Unknown->value);
            $competence = $client->getAttribute('portfolio_competence');
            $lastConsulted = $client->getAttribute('portfolio_last_consulted_at');
            $cnpj = $matrixCnpjs[$id] ?? null;
            $normalizedCnpj = Cnpj::normalize($cnpj ?? (string) $client->root_cnpj);
            $detail = $details[$id] ?? ['module_key' => $module->value];
            $coverage = $coverages[$id] ?? FiscalCoverage::Unknown->value;
            if ($module === FiscalModuleKey::Fgts
                && is_string($detail['coverage'] ?? null)
            ) {
                $coverage = (string) $detail['coverage'];
            }
            $deadline = $deadlines[$id] ?? null;

            return new ModuleClientRowDto(
                moduleKey: $module,
                clientId: $id,
                legalName: (string) $client->legal_name,
                displayName: $client->display_name,
                cnpj: strlen($normalizedCnpj) === 14 ? $normalizedCnpj : null,
                competence: is_string($competence) && $competence !== '' ? $competence : null,
                situation: $situation,
                coverage: $coverage,
                dataOrigin: $origin,
                lastConsultedAt: is_string($lastConsulted) && $lastConsulted !== ''
                    ? CarbonImmutable::parse($lastConsulted)->toIso8601String()
                    : null,
                nextDeadlineAt: $deadline['due_at'] ?? null,
                nextAction: $this->nextActionFor($module, $situation, $detail),
                detail: $detail,
                links: $this->rowLinks($module, $id, $detail),
                document: $documents[$id] ?? null,
            );
        });

        return new ConcretePaginator(
            $rows->values(),
            $paginator->total(),
            $paginator->perPage(),
            $paginator->currentPage(),
            [
                'path' => $paginator->path(),
                'pageName' => $paginator->getPageName(),
            ],
        );
    }

    /**
     * Linhas sanitizadas da carteira para export assíncrono (sem paginação UI).
     * Nunca inclui PFX, PEM, vault, tokens, XML ou CNPJ completo.
     *
     * @return array{
     *     module_key: string,
     *     data_origin: FiscalDataOrigin,
     *     is_demonstration: bool,
     *     rows: list<array<string, mixed>>,
     *     total: int
     * }
     */
    public function exportSanitizedRows(
        Tenant $tenant,
        FiscalModuleKey $module,
        ModulePortfolioFilters $filters,
    ): array {
        $origin = $this->dataOrigin->resolve($tenant);
        $rows = [];
        $page = 1;
        $total = 0;
        $maxPages = 100; // teto 10k linhas (100 × 100)

        do {
            $pageFilters = $filters->withPage($page, 100);
            $paginator = $this->clients($tenant, $module, $pageFilters);
            $total = (int) $paginator->total();

            /** @var ModuleClientRowDto $dto */
            foreach ($paginator->items() as $dto) {
                $rows[] = $this->sanitizeExportRow($dto);
            }

            $page++;
            $lastPage = max(1, (int) $paginator->lastPage());
        } while ($page <= $lastPage && $page <= $maxPages);

        return [
            'module_key' => $module->value,
            'data_origin' => $origin,
            'is_demonstration' => $origin->isSynthetic(),
            'rows' => $rows,
            'total' => $total,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sanitizeExportRow(ModuleClientRowDto $dto): array
    {
        $detail = $this->sanitizeExportDetail($dto->detail);

        return [
            'module_key' => $dto->moduleKey->value,
            'client_id' => $dto->clientId,
            'legal_name' => $dto->legalName,
            'display_name' => $dto->displayName,
            'cnpj' => $dto->cnpj,
            'competence' => $dto->competence,
            'situation' => $dto->situation,
            'coverage' => $dto->coverage,
            'data_origin' => $dto->dataOrigin->value,
            'last_consulted_at' => $dto->lastConsultedAt,
            'next_deadline_at' => $dto->nextDeadlineAt,
            'next_action' => $dto->nextAction,
            'metrics' => $detail,
        ];
    }

    /**
     * Remove chaves sensíveis e links internos do bloco discriminado.
     *
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>
     */
    private function sanitizeExportDetail(array $detail): array
    {
        unset($detail['links']);

        $blockedFragments = [
            'pfx', 'pem', 'password', 'passwd', 'secret', 'token', 'vault',
            'private_key', 'privatekey', 'certificate', 'consumer', 'xml',
            'raw_payload', 'payload_raw', 'mtls', 'keystore',
        ];

        $walk = function (mixed $value) use (&$walk, $blockedFragments): mixed {
            if (! is_array($value)) {
                if (is_string($value) && strlen($value) > 2000) {
                    return null;
                }

                return $value;
            }

            $out = [];
            foreach ($value as $key => $item) {
                if (! is_string($key) && ! is_int($key)) {
                    continue;
                }
                $keyStr = strtolower((string) $key);
                $blocked = false;
                foreach ($blockedFragments as $frag) {
                    if (str_contains($keyStr, $frag)) {
                        $blocked = true;
                        break;
                    }
                }
                if ($blocked || $keyStr === 'links') {
                    continue;
                }
                $out[$key] = $walk($item);
            }

            return $out;
        };

        /** @var array<string, mixed> $sanitized */
        $sanitized = $walk($detail);

        return $sanitized;
    }

    /**
     * IDs de clientes no escopo filtrado (SQL, reutilizável por overview/lista).
     *
     * @return Builder<Client>|QueryBuilder
     */
    public function scopedClientIdsQuery(
        Tenant $tenant,
        FiscalModuleKey $module,
        ModulePortfolioFilters $filters,
    ): Builder|QueryBuilder {
        $q = Client::query()
            ->withoutGlobalScopes()
            ->select('clients.id')
            ->where('clients.tenant_id', $tenant->id)
            ->where('clients.is_active', true);

        $clientIds = $filters->clientIdList();
        if ($clientIds !== []) {
            if (count($clientIds) === 1) {
                $q->where('clients.id', $clientIds[0]);
            } else {
                $q->whereIn('clients.id', $clientIds);
            }
        }

        if ($filters->q !== null) {
            $this->applySearch($q, $filters->q);
        }

        $situations = $filters->situationList();
        if ($situations !== []) {
            $expr = '('.$this->situationSqlExpression($tenant, $module, $filters).')';
            if (count($situations) === 1) {
                $q->whereRaw($expr.' = ?', [$situations[0]]);
            } else {
                $placeholders = implode(',', array_fill(0, count($situations), '?'));
                $q->whereRaw($expr.' IN ('.$placeholders.')', $situations);
            }
        }

        if ($filters->competence !== null) {
            $comp = $filters->competence;
            $q->whereExists(function (QueryBuilder $exists) use ($tenant, $module, $filters, $comp): void {
                $exists->select(DB::raw('1'))
                    ->from('fiscal_competences as fc')
                    ->whereColumn('fc.client_id', 'clients.id')
                    ->where('fc.tenant_id', $tenant->id)
                    ->where('fc.period_key', $comp);

                $catIds = $this->categoryIdsForModule($module, $filters->submodule);
                if ($catIds !== []) {
                    $exists->where(function (QueryBuilder $inner) use ($catIds): void {
                        $inner->whereIn('fc.fiscal_category_id', $catIds)
                            ->orWhereNull('fc.fiscal_category_id');
                    });
                }
            });
        }

        $deliveries = $filters->deliveryStatusList();
        if ($deliveries !== [] && $module === FiscalModuleKey::Declarations) {
            $obligationCode = $this->declarationsObligationCode($filters->submodule);
            $q->whereExists(function (QueryBuilder $exists) use ($tenant, $deliveries, $obligationCode): void {
                $exists->select(DB::raw('1'))
                    ->from('tax_obligation_projections as top')
                    ->whereColumn('top.client_id', 'clients.id')
                    ->where('top.tenant_id', $tenant->id)
                    ->whereIn('top.delivery_status', $deliveries);
                if ($obligationCode !== null) {
                    $exists->join('tax_obligation_definitions as tod', 'tod.id', '=', 'top.obligation_definition_id')
                        ->where('tod.code', $obligationCode);
                }
            });
        }

        if ($module === FiscalModuleKey::Declarations) {
            $this->applyDeclarationsSubmoduleScope($q, $tenant, $filters);
        }

        if ($module === FiscalModuleKey::SimplesMei) {
            $this->applySimplesMeiSubmoduleScope($q, $filters);
            $this->applyPgdasdSendStatusFilter($q, $tenant, $filters);
        }

        $this->membership->applyExclusionScope($q, $tenant, $module, $filters->submodule);

        if ($filters->coverageList() !== []) {
            $this->applyCoverageFilter($q, $tenant, $module, $filters);
        }

        $modalities = $filters->modalityList();
        if ($modalities !== [] && $module === FiscalModuleKey::Installments) {
            $q->whereExists(function (QueryBuilder $exists) use ($tenant, $modalities): void {
                $exists->select(DB::raw('1'))
                    ->from('tax_installment_orders as tio')
                    ->whereColumn('tio.client_id', 'clients.id')
                    ->where('tio.tenant_id', $tenant->id)
                    ->whereIn('tio.modality', $modalities);
            });
        }

        return $q;
    }

    /**
     * Filtra pela cobertura efetiva (snapshot corrente do módulo, senão vínculo de categoria).
     * Alinhado a loadCoverages() — não inventa FULL.
     */
    private function applyCoverageFilter(
        Builder|QueryBuilder $q,
        Tenant $tenant,
        FiscalModuleKey $module,
        ModulePortfolioFilters $filters,
    ): void {
        $coverages = $filters->coverageList();
        if ($coverages === []) {
            return;
        }

        $systemCodes = $this->systemCodesForModule($module, $filters->submodule);
        $categoryIds = $this->categoryIdsForModule($module, $filters->submodule);
        $sysList = $this->quoteList($systemCodes);

        // Agrega todas as dimensões correntes com a mesma política do payload.
        $snapshotCoverage = <<<SQL
(
    SELECT CASE
        WHEN SUM(CASE WHEN fs.coverage = 'PARTIAL' THEN 1 ELSE 0 END) > 0 THEN 'PARTIAL'
        WHEN SUM(CASE WHEN fs.coverage = 'FULL' THEN 1 ELSE 0 END) > 0
         AND SUM(CASE WHEN fs.coverage IN ('UNSUPPORTED', 'UNKNOWN') THEN 1 ELSE 0 END) > 0 THEN 'PARTIAL'
        WHEN SUM(CASE WHEN fs.coverage = 'FULL' THEN 1 ELSE 0 END) > 0 THEN 'FULL'
        WHEN SUM(CASE WHEN fs.coverage = 'UNKNOWN' THEN 1 ELSE 0 END) > 0 THEN 'UNKNOWN'
        WHEN SUM(CASE WHEN fs.coverage = 'UNSUPPORTED' THEN 1 ELSE 0 END) > 0 THEN 'UNSUPPORTED'
        WHEN SUM(CASE WHEN fs.coverage = 'NOT_APPLICABLE' THEN 1 ELSE 0 END) > 0 THEN 'NOT_APPLICABLE'
        ELSE NULL
    END
    FROM fiscal_snapshots fs
    WHERE fs.tenant_id = {$tenant->id}
      AND fs.client_id = clients.id
      AND fs.is_current = true
      AND fs.system_code IN ({$sysList})
)
SQL;

        $catList = $categoryIds === []
            ? 'NULL'
            : implode(',', array_map(static fn (int $id): string => (string) $id, $categoryIds));

        $linkCoverage = <<<SQL
(
    SELECT CASE
        WHEN SUM(CASE WHEN ofcl.coverage = 'PARTIAL' THEN 1 ELSE 0 END) > 0 THEN 'PARTIAL'
        WHEN SUM(CASE WHEN ofcl.coverage = 'FULL' THEN 1 ELSE 0 END) > 0
         AND SUM(CASE WHEN ofcl.coverage IN ('UNSUPPORTED', 'UNKNOWN') THEN 1 ELSE 0 END) > 0 THEN 'PARTIAL'
        WHEN SUM(CASE WHEN ofcl.coverage = 'FULL' THEN 1 ELSE 0 END) > 0 THEN 'FULL'
        WHEN SUM(CASE WHEN ofcl.coverage = 'UNKNOWN' THEN 1 ELSE 0 END) > 0 THEN 'UNKNOWN'
        WHEN SUM(CASE WHEN ofcl.coverage = 'UNSUPPORTED' THEN 1 ELSE 0 END) > 0 THEN 'UNSUPPORTED'
        WHEN SUM(CASE WHEN ofcl.coverage = 'NOT_APPLICABLE' THEN 1 ELSE 0 END) > 0 THEN 'NOT_APPLICABLE'
        ELSE NULL
    END
    FROM tenant_fiscal_category_links ofcl
    WHERE ofcl.tenant_id = {$tenant->id}
      AND ofcl.client_id = clients.id
      AND ofcl.status = 'ACTIVE'
      AND ofcl.fiscal_category_id IN ({$catList})
)
SQL;

        $expr = "COALESCE({$snapshotCoverage}, {$linkCoverage}, 'UNKNOWN')";
        if (count($coverages) === 1) {
            $q->whereRaw("{$expr} = ?", [$coverages[0]]);
        } else {
            $placeholders = implode(',', array_fill(0, count($coverages), '?'));
            $q->whereRaw("{$expr} IN ({$placeholders})", $coverages);
        }
    }

    private function aggregateCounters(
        Tenant $tenant,
        FiscalModuleKey $module,
        ModulePortfolioFilters $filters,
    ): ModuleCountersDto {
        // Contadores no escopo completo — situation filter removed so KPI strip stays useful;
        // other filters (q, competence, submodule) still apply via a clone without situation.
        // total_clients do overview usa a soma deste mesmo mapa (partição exaustiva).
        $scopeFilters = new ModulePortfolioFilters(
            page: 1,
            perPage: 1,
            q: $filters->q,
            situation: null,
            competence: $filters->competence,
            submodule: $filters->submodule,
            deliveryStatus: $filters->deliveryStatus,
            sort: $filters->sort,
            sortDirection: $filters->sortDirection,
            clientId: $filters->clientId,
            coverage: $filters->coverage,
            modality: $filters->modality,
            year: $filters->year,
            sendStatus: $filters->sendStatus,
        );

        $ids = $this->scopedClientIdsQuery($tenant, $module, $scopeFilters);
        $expr = $this->situationSqlExpression($tenant, $module, $scopeFilters);

        $rows = Client::query()
            ->withoutGlobalScopes()
            ->from('clients')
            ->whereIn('clients.id', $ids)
            ->where('clients.tenant_id', $tenant->id)
            ->selectRaw("({$expr}) as sit")
            ->selectRaw('COUNT(*) as cnt')
            ->groupBy(DB::raw("({$expr})"))
            ->get();

        $map = [];
        foreach (FiscalSituation::cases() as $case) {
            $map[$case->value] = 0;
        }

        foreach ($rows as $row) {
            $sit = (string) $row->sit;
            // Valor fora do enum canônico → UNKNOWN (fail-closed).
            if (! array_key_exists($sit, $map)) {
                $sit = FiscalSituation::Unknown->value;
            }
            $map[$sit] += (int) $row->cnt;
        }

        return ModuleCountersDto::fromSituationMap($map);
    }

    /**
     * @return list<ModuleAgendaItemDto>
     */
    private function buildAgenda(
        Tenant $tenant,
        FiscalModuleKey $module,
        ModulePortfolioFilters $filters,
    ): array {
        $catIds = $this->categoryIdsForModule($module, $filters->submodule);
        $clientIds = $this->scopedClientIdsQuery($tenant, $module, $filters);

        $items = FiscalCompetence::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereIn('client_id', $clientIds)
            ->whereNotNull('due_at')
            ->whereNull('closed_at')
            ->where('due_at', '>=', now()->subDays(1))
            ->when($catIds !== [], fn ($q) => $q->where(function ($inner) use ($catIds): void {
                $inner->whereIn('fiscal_category_id', $catIds)
                    ->orWhereNull('fiscal_category_id');
            }))
            ->orderBy('due_at')
            ->limit(8)
            ->get(['client_id', 'period_key', 'due_at', 'situation']);

        return $items->map(fn (FiscalCompetence $c) => new ModuleAgendaItemDto(
            clientId: (int) $c->client_id,
            label: 'Competência '.$c->period_key,
            dueAt: $c->due_at?->toIso8601String(),
            situation: $c->situation?->value,
            href: '/api/v1/clients/'.$c->client_id,
        ))->all();
    }

    /**
     * @param  Collection<int, FiscalCategory>  $categories
     * @return list<ModuleCategorySummaryDto>
     */
    private function categorySummaries(
        Tenant $tenant,
        Collection $categories,
        ModulePortfolioFilters $filters,
    ): array {
        if ($categories->isEmpty()) {
            return [];
        }

        $counts = TenantFiscalCategoryLink::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('status', FiscalLinkStatus::Active->value)
            ->whereIn('fiscal_category_id', $categories->pluck('id'))
            ->selectRaw('fiscal_category_id, COUNT(DISTINCT client_id) as cnt')
            ->groupBy('fiscal_category_id')
            ->pluck('cnt', 'fiscal_category_id');

        return $categories->map(fn (FiscalCategory $cat) => new ModuleCategorySummaryDto(
            id: (int) $cat->id,
            code: (string) $cat->code,
            name: (string) $cat->name,
            defaultCoverage: $cat->default_coverage?->value,
            linkedClients: (int) ($counts[$cat->id] ?? 0),
        ))->values()->all();
    }

    private function latestObservedAt(
        Tenant $tenant,
        FiscalModuleKey $module,
        ModulePortfolioFilters $filters,
    ): ?string {
        $systemCodes = $this->systemCodesForModule($module, $filters->submodule);
        if ($systemCodes === []) {
            return null;
        }

        $at = FiscalSnapshot::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('is_current', true)
            ->whereIn('system_code', $systemCodes)
            ->whereIn('client_id', $this->scopedClientIdsQuery($tenant, $module, $filters))
            ->max('observed_at');

        return $at !== null ? CarbonImmutable::parse((string) $at)->toIso8601String() : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function moduleMetrics(
        Tenant $tenant,
        FiscalModuleKey $module,
        ModulePortfolioFilters $filters,
        int $totalClients,
    ): array {
        return match ($module) {
            FiscalModuleKey::Fgts => [
                'partial_coverage' => true,
                'guide_payment_supported' => false,
            ],
            FiscalModuleKey::Mailbox => [
                'open_messages' => MailboxMessage::query()
                    ->withoutGlobalScopes()
                    ->where('tenant_id', $tenant->id)
                    ->whereIn('client_id', $this->scopedClientIdsQuery($tenant, $module, $filters))
                    ->whereIn('triage_status', ['NEW', 'IN_REVIEW'])
                    ->count(),
            ],
            FiscalModuleKey::Guides => [
                'unconfirmed_payment_guides' => TaxGuide::query()
                    ->withoutGlobalScopes()
                    ->where('tenant_id', $tenant->id)
                    ->whereIn('client_id', $this->scopedClientIdsQuery($tenant, $module, $filters))
                    ->whereIn('payment_status', ['UNKNOWN', 'NOT_CONFIRMED'])
                    ->count(),
            ],
            FiscalModuleKey::Installments => [
                'total_clients' => $totalClients,
                'tab_counts' => $this->installmentTabCounts($tenant, $filters),
            ],
            FiscalModuleKey::Declarations => [
                'total_clients' => $totalClients,
                'tab_counts' => $this->declarationsTabCounts($tenant, $filters),
            ],
            default => [
                'total_clients' => $totalClients,
            ],
        };
    }

    /**
     * Contagens estáveis das tabs de modalidade: preserva filtros globais e
     * substitui somente `modality`, a dimensão controlada pela própria faixa.
     *
     * @return array<string, int>
     */
    private function installmentTabCounts(
        Tenant $tenant,
        ModulePortfolioFilters $filters,
    ): array {
        $counts = [
            'all' => $this->countTabClients(
                $tenant,
                FiscalModuleKey::Installments,
                $this->installmentTabFilters($filters, null),
            ),
        ];

        foreach (TaxInstallmentModality::cases() as $modality) {
            $counts[$modality->value] = $this->countTabClients(
                $tenant,
                FiscalModuleKey::Installments,
                $this->installmentTabFilters($filters, $modality->value),
            );
        }

        // Catálogo visível, mas sem read model executável.
        $counts['PARC-PAEX'] = 0;
        $counts['PARC-SIPADE'] = 0;

        return $counts;
    }

    /**
     * Contagens estáveis das tabs de obrigação: preserva filtros globais e
     * substitui somente `submodule`, a dimensão controlada pela própria faixa.
     *
     * @return array<string, int>
     */
    private function declarationsTabCounts(
        Tenant $tenant,
        ModulePortfolioFilters $filters,
    ): array {
        $counts = [];
        foreach (FiscalModuleKey::Declarations->knownSubmodules() as $submodule) {
            if ($submodule === 'DECLARACOES') {
                continue;
            }

            $counts[$submodule] = $submodule === 'DIRF'
                ? 0
                : $this->countTabClients(
                    $tenant,
                    FiscalModuleKey::Declarations,
                    $this->declarationsTabFilters($filters, $submodule),
                );
        }

        return $counts;
    }

    private function countTabClients(
        Tenant $tenant,
        FiscalModuleKey $module,
        ModulePortfolioFilters $filters,
    ): int {
        return (int) $this->scopedClientIdsQuery($tenant, $module, $filters)->count();
    }

    private function installmentTabFilters(
        ModulePortfolioFilters $filters,
        ?string $modality,
    ): ModulePortfolioFilters {
        return new ModulePortfolioFilters(
            page: 1,
            perPage: 1,
            q: $filters->q,
            situation: null,
            competence: $filters->competence,
            submodule: $filters->submodule,
            deliveryStatus: $filters->deliveryStatus,
            sort: $filters->sort,
            sortDirection: $filters->sortDirection,
            clientId: $filters->clientId,
            coverage: $filters->coverage,
            modality: $modality,
            year: $filters->year,
            sendStatus: $filters->sendStatus,
        );
    }

    private function declarationsTabFilters(
        ModulePortfolioFilters $filters,
        string $submodule,
    ): ModulePortfolioFilters {
        return new ModulePortfolioFilters(
            page: 1,
            perPage: 1,
            q: $filters->q,
            situation: null,
            competence: $filters->competence,
            submodule: $submodule,
            deliveryStatus: $filters->deliveryStatus,
            sort: $filters->sort,
            sortDirection: $filters->sortDirection,
            clientId: $filters->clientId,
            coverage: $filters->coverage,
            modality: $filters->modality,
            year: $filters->year,
            sendStatus: $filters->sendStatus,
        );
    }

    /**
     * Expressão SQL escalar de situação por cliente (mesmo critério de contadores e lista).
     */
    private function situationSqlExpression(
        Tenant $tenant,
        FiscalModuleKey $module,
        ModulePortfolioFilters $filters,
    ): string {
        $systemCodes = $this->systemCodesForModule($module, $filters->submodule);
        $serviceCodes = $this->serviceCodesForModule($module, $filters->submodule);

        $sysList = $this->quoteList($systemCodes);
        $svcList = $this->quoteList($serviceCodes);

        // Snapshot corrente mais recente do(s) system/service do módulo.
        // COALESCE com UNKNOWN quando não há projeção.
        $snapshotSql = <<<SQL
(
    SELECT fs.situation
    FROM fiscal_snapshots fs
    WHERE fs.tenant_id = {$tenant->id}
      AND fs.client_id = clients.id
      AND fs.is_current = true
      AND fs.system_code IN ({$sysList})
SQL;
        if ($serviceCodes !== []) {
            $snapshotSql .= " AND (fs.service_code IN ({$svcList}) OR fs.service_code IS NULL)";
        }
        $snapshotSql .= ' ORDER BY fs.observed_at DESC, fs.id DESC LIMIT 1)';

        // Complementos por módulo (projeções específicas têm prioridade se existirem)
        $moduleSql = match ($module) {
            FiscalModuleKey::Dctfweb => $this->dctfwebSituationSql($tenant, $filters),
            FiscalModuleKey::SimplesMei => $this->simplesMeiSituationSql($tenant, $filters),
            FiscalModuleKey::Installments => $this->parcelamentosSituationSql($tenant, $filters),
            FiscalModuleKey::Mailbox => $this->mailboxSituationSql($tenant),
            FiscalModuleKey::Declarations => $this->declaracoesSituationSql($tenant, $filters),
            FiscalModuleKey::Guides => $this->guiasSituationSql($tenant),
            FiscalModuleKey::Fgts => $this->fgtsSituationSql($tenant),
            default => null,
        };

        if ($moduleSql !== null) {
            return "COALESCE({$moduleSql}, {$snapshotSql}, 'UNKNOWN')";
        }

        return "COALESCE({$snapshotSql}, 'UNKNOWN')";
    }

    /**
     * Situação operacional do Simples/MEI a partir das projeções próprias
     * (não do snapshot genérico, que costuma cair em BLOCKED/UNKNOWN).
     * PGDAS-D: tax_obligation_projections.situation (já mapeada no post-consult).
     * PGMEI: debt_state da projeção anual → FiscalSituation.
     */
    private function simplesMeiSituationSql(Tenant $tenant, ModulePortfolioFilters $filters): string
    {
        $sub = strtoupper((string) ($filters->submodule ?? 'PGDASD'));

        if ($sub === 'PGMEI') {
            $year = $filters->year;
            // O ano é normalizado em PHP para manter a expressão simples.
            $resolvedYear = ($year !== null && $year >= 2000)
                ? (int) $year
                : (int) now()->year;
            $yearClause = 'AND pdp.calendar_year = '.$resolvedYear;

            return "(
                SELECT CASE UPPER(COALESCE(pdp.debt_state, 'UNVERIFIED'))
                    WHEN 'NO_ACTIVE_DEBT' THEN 'UP_TO_DATE'
                    WHEN 'HAS_ACTIVE_DEBT' THEN 'PENDING'
                    ELSE 'UNKNOWN'
                END
                FROM pgmei_debt_projections pdp
                WHERE pdp.tenant_id = {$tenant->id}
                  AND pdp.client_id = clients.id
                  {$yearClause}
                ORDER BY pdp.last_valid_query_at DESC NULLS LAST, pdp.id DESC
                LIMIT 1
            )";
        }

        // PGDASD (default): situação canônica gravada na projeção PGDAS-D.
        return "(
            SELECT top.situation
            FROM tax_obligation_projections top
            INNER JOIN tax_obligation_definitions tod
              ON tod.id = top.obligation_definition_id
            WHERE top.tenant_id = {$tenant->id}
              AND top.client_id = clients.id
              AND tod.code = 'PGDAS_D'
              AND top.situation IS NOT NULL
            ORDER BY top.last_valid_query_at DESC NULLS LAST, top.id DESC
            LIMIT 1
        )";
    }

    private function dctfwebSituationSql(Tenant $tenant, ModulePortfolioFilters $filters): string
    {
        $sub = $filters->submodule;
        if ($sub === 'MIT') {
            return "(
                SELECT ma.situation FROM mit_assessments ma
                WHERE ma.tenant_id = {$tenant->id} AND ma.client_id = clients.id
                ORDER BY ma.observed_at DESC, ma.id DESC LIMIT 1
            )";
        }

        if ($sub === 'DCTFWEB') {
            return "(
                SELECT dd.situation FROM dctfweb_declarations dd
                WHERE dd.tenant_id = {$tenant->id} AND dd.client_id = clients.id
                ORDER BY dd.official_at DESC, dd.id DESC LIMIT 1
            )";
        }

        // Ambos: pior situação entre as duas fontes (prioridade fixa via CASE)
        return "(
            SELECT sit FROM (
                SELECT dd.situation AS sit, 1 AS src_ord, COALESCE(dd.official_at, dd.updated_at) AS ord_at, dd.id AS ord_id
                FROM dctfweb_declarations dd
                WHERE dd.tenant_id = {$tenant->id} AND dd.client_id = clients.id
                UNION ALL
                SELECT ma.situation, 2, COALESCE(ma.observed_at, ma.updated_at), ma.id
                FROM mit_assessments ma
                WHERE ma.tenant_id = {$tenant->id} AND ma.client_id = clients.id
            ) u
            ORDER BY
                CASE u.sit
                    WHEN 'ERROR' THEN 1 WHEN 'BLOCKED' THEN 2 WHEN 'ATTENTION' THEN 3
                    WHEN 'PENDING' THEN 4 WHEN 'PROCESSING' THEN 5 WHEN 'UNKNOWN' THEN 6
                    WHEN 'UNSUPPORTED' THEN 7 WHEN 'NOT_APPLICABLE' THEN 8 WHEN 'UP_TO_DATE' THEN 9
                    ELSE 10
                END ASC,
                u.ord_at DESC, u.ord_id DESC
            LIMIT 1
        )";
    }

    private function parcelamentosSituationSql(Tenant $tenant, ModulePortfolioFilters $filters): string
    {
        $modalityList = $filters->modalityList();
        $modalitySql = $modalityList === [] ? null : $this->quoteList($modalityList);
        $parcelModalityClause = $modalitySql === null ? '' : " AND tip.modality IN ({$modalitySql})";
        $orderModalityClause = $modalitySql === null ? '' : " AND tio.modality IN ({$modalitySql})";

        // Mapeia situação textual do pedido + atraso de parcela
        return "(
            SELECT CASE
                WHEN EXISTS (
                    SELECT 1 FROM tax_installment_parcels tip
                    WHERE tip.tenant_id = {$tenant->id} AND tip.client_id = clients.id
                      AND tip.status IN ('ATTENTION','PENDING')
                      {$parcelModalityClause}
                ) THEN 'ATTENTION'
                WHEN EXISTS (
                    SELECT 1 FROM tax_installment_orders tio
                    WHERE tio.tenant_id = {$tenant->id} AND tio.client_id = clients.id
                      AND UPPER(COALESCE(tio.situation,'')) IN ('ATTENTION','PENDING','ERROR','BLOCKED')
                      {$orderModalityClause}
                ) THEN (
                    SELECT UPPER(tio.situation) FROM tax_installment_orders tio
                    WHERE tio.tenant_id = {$tenant->id} AND tio.client_id = clients.id
                      {$orderModalityClause}
                    ORDER BY tio.observed_at DESC, tio.id DESC LIMIT 1
                )
                WHEN EXISTS (
                    SELECT 1 FROM tax_installment_orders tio
                    WHERE tio.tenant_id = {$tenant->id} AND tio.client_id = clients.id
                      {$orderModalityClause}
                ) THEN COALESCE((
                    SELECT UPPER(tio.situation) FROM tax_installment_orders tio
                    WHERE tio.tenant_id = {$tenant->id} AND tio.client_id = clients.id
                      {$orderModalityClause}
                    ORDER BY tio.observed_at DESC, tio.id DESC LIMIT 1
                ), 'UNKNOWN')
                ELSE NULL
            END
        )";
    }

    private function mailboxSituationSql(Tenant $tenant): string
    {
        return "(
            SELECT CASE
                WHEN EXISTS (
                    SELECT 1 FROM mailbox_messages mm
                    WHERE mm.tenant_id = {$tenant->id} AND mm.client_id = clients.id
                      AND mm.due_at IS NOT NULL AND mm.due_at < CURRENT_TIMESTAMP
                      AND mm.triage_status IN ('NEW','IN_REVIEW')
                ) THEN 'ATTENTION'
                WHEN EXISTS (
                    SELECT 1 FROM mailbox_messages mm
                    WHERE mm.tenant_id = {$tenant->id} AND mm.client_id = clients.id
                      AND mm.triage_status = 'NEW'
                ) THEN 'PENDING'
                WHEN EXISTS (
                    SELECT 1 FROM mailbox_contributor_states mcs
                    WHERE mcs.tenant_id = {$tenant->id} AND mcs.client_id = clients.id
                      AND COALESCE(mcs.official_unread_count, 0) > 0
                ) THEN 'PENDING'
                WHEN EXISTS (
                    SELECT 1 FROM mailbox_contributor_states mcs
                    WHERE mcs.tenant_id = {$tenant->id} AND mcs.client_id = clients.id
                ) THEN 'UP_TO_DATE'
                ELSE NULL
            END
        )";
    }

    private function declaracoesSituationSql(Tenant $tenant, ModulePortfolioFilters $filters): string
    {
        $sub = strtoupper((string) ($filters->submodule ?? ''));
        if ($sub === 'DIRF') {
            return "'UNSUPPORTED'";
        }
        if ($sub === 'FGTS') {
            return $this->fgtsSituationSql($tenant);
        }

        $obligationCode = $this->declarationsObligationCode($filters->submodule);
        $codeClause = $obligationCode !== null
            ? "AND tod.code = '".$this->escapeSqlLiteral($obligationCode)."'"
            : '';

        return "(
            SELECT top.situation FROM tax_obligation_projections top
            INNER JOIN tax_obligation_definitions tod
              ON tod.id = top.obligation_definition_id
            WHERE top.tenant_id = {$tenant->id} AND top.client_id = clients.id
              AND top.is_open = true
              {$codeClause}
            ORDER BY
                CASE top.situation
                    WHEN 'ERROR' THEN 1 WHEN 'BLOCKED' THEN 2 WHEN 'ATTENTION' THEN 3
                    WHEN 'PENDING' THEN 4 WHEN 'PROCESSING' THEN 5 ELSE 9
                END ASC,
                CASE WHEN top.due_at IS NULL THEN 1 ELSE 0 END ASC,
                top.due_at ASC, top.id DESC
            LIMIT 1
        )";
    }

    private function guiasSituationSql(Tenant $tenant): string
    {
        return "(
            SELECT CASE
                WHEN EXISTS (
                    SELECT 1 FROM tax_guides tg
                    WHERE tg.tenant_id = {$tenant->id} AND tg.client_id = clients.id
                      AND tg.due_at IS NOT NULL AND tg.due_at < CURRENT_TIMESTAMP
                      AND tg.payment_status IN ('UNKNOWN','NOT_CONFIRMED')
                ) THEN 'ATTENTION'
                WHEN EXISTS (
                    SELECT 1 FROM tax_guides tg
                    WHERE tg.tenant_id = {$tenant->id} AND tg.client_id = clients.id
                      AND tg.payment_status IN ('UNKNOWN','NOT_CONFIRMED')
                ) THEN 'PENDING'
                WHEN EXISTS (
                    SELECT 1 FROM tax_guides tg
                    WHERE tg.tenant_id = {$tenant->id} AND tg.client_id = clients.id
                      AND tg.payment_status IN ('CONFIRMED','PARTIAL')
                ) THEN 'UP_TO_DATE'
                ELSE NULL
            END
        )";
    }

    private function fgtsSituationSql(Tenant $tenant): string
    {
        return "(
            SELECT fcs.situation FROM fgts_competence_statuses fcs
            WHERE fcs.tenant_id = {$tenant->id} AND fcs.client_id = clients.id
              AND fcs.is_quarantined = false
            ORDER BY fcs.last_synced_at DESC, fcs.id DESC LIMIT 1
        )";
    }

    private function situationSubquery(
        Tenant $tenant,
        FiscalModuleKey $module,
        ModulePortfolioFilters $filters,
    ): QueryBuilder {
        return DB::query()->selectRaw($this->situationSqlExpression($tenant, $module, $filters));
    }

    private function isPgdasdPortfolioSubmodule(
        FiscalModuleKey $module,
        ModulePortfolioFilters $filters,
    ): bool {
        if ($module !== FiscalModuleKey::SimplesMei) {
            return false;
        }

        $sub = strtoupper((string) ($filters->submodule ?? 'PGDASD'));

        return in_array($sub, ['', 'PGDASD', 'PGDAS', 'SIMPLES'], true);
    }

    private function pgdasdDeclarationStateSortSubquery(Tenant $tenant): QueryBuilder
    {
        return DB::query()->selectRaw(<<<SQL
(
    SELECT top.pgdasd_declaration_state
    FROM tax_obligation_projections top
    INNER JOIN tax_obligation_definitions tod
      ON tod.id = top.obligation_definition_id
    WHERE top.tenant_id = {$tenant->id}
      AND top.client_id = clients.id
      AND tod.code = 'PGDAS_D'
    ORDER BY top.last_valid_query_at DESC NULLS LAST, top.id DESC
    LIMIT 1
)
SQL);
    }

    private function pgdasdLastDeclarationSortSubquery(Tenant $tenant): QueryBuilder
    {
        return DB::query()->selectRaw(<<<SQL
(
    SELECT po.period_key
    FROM pgdasd_operations po
    WHERE po.tenant_id = {$tenant->id}
      AND po.client_id = clients.id
      AND po.kind = 'DECLARATION'
    ORDER BY po.transmitted_at DESC NULLS LAST, po.declaration_number DESC NULLS LAST, po.id DESC
    LIMIT 1
)
SQL);
    }

    private function pgdasdRbt12SortSubquery(Tenant $tenant): QueryBuilder
    {
        $tz = is_string($tenant->timezone) && $tenant->timezone !== ''
            ? $tenant->timezone
            : 'America/Sao_Paulo';
        $expectedPeriodKey = PgdasdPeriod::toPeriodKey(PgdasdPeriod::expectedPa(null, $tz));
        $expectedPeriodSql = $this->escapeSqlLiteral($expectedPeriodKey);
        $tenantId = (int) $tenant->id;

        // Período de display = declaração do PA esperado se existir; senão última
        // declaração; senão PA esperado — alinhado a portfolioDetails.
        // Subselects correlacionam em pr.client_id dentro do ORDER BY aninhado.
        return DB::query()->selectRaw(<<<SQL
(
    SELECT pr.total_cents
    FROM pgdasd_rbt12_projections pr
    LEFT JOIN tax_obligation_projections top_rbt
      ON top_rbt.id = pr.projection_id
    WHERE pr.tenant_id = {$tenantId}
      AND pr.client_id = clients.id
    ORDER BY
      CASE
        WHEN pr.status = 'PARSED' AND top_rbt.period_key = COALESCE(
          (
            SELECT po.period_key
            FROM pgdasd_operations po
            WHERE po.tenant_id = {$tenantId}
              AND po.client_id = pr.client_id
              AND po.kind = 'DECLARATION'
              AND po.period_key = '{$expectedPeriodSql}'
            ORDER BY po.transmitted_at DESC NULLS LAST, po.declaration_number DESC NULLS LAST, po.id DESC
            LIMIT 1
          ),
          (
            SELECT po.period_key
            FROM pgdasd_operations po
            WHERE po.tenant_id = {$tenantId}
              AND po.client_id = pr.client_id
              AND po.kind = 'DECLARATION'
            ORDER BY po.transmitted_at DESC NULLS LAST, po.declaration_number DESC NULLS LAST, po.id DESC
            LIMIT 1
          ),
          '{$expectedPeriodSql}'
        ) THEN 0
        WHEN pr.status = 'PARSED' THEN 1
        ELSE 2
      END ASC,
      pr.id DESC
    LIMIT 1
)
SQL);
    }

    private function lastConsultedSubquery(
        Tenant $tenant,
        FiscalModuleKey $module,
        ModulePortfolioFilters $filters,
    ): QueryBuilder {
        $submodule = strtoupper((string) ($filters->submodule ?? ''));
        if ($module === FiscalModuleKey::SimplesMei && in_array($submodule, ['', 'PGDASD'], true)) {
            return DB::query()->selectRaw(<<<SQL
(
    SELECT top.last_valid_query_at
    FROM tax_obligation_projections top
    INNER JOIN tax_obligation_definitions tod
      ON tod.id = top.obligation_definition_id
    WHERE top.tenant_id = {$tenant->id}
      AND top.client_id = clients.id
      AND tod.code = 'PGDAS_D'
      AND top.last_valid_query_at IS NOT NULL
    ORDER BY top.last_valid_query_at DESC, top.id DESC LIMIT 1
)
SQL);
        }

        if ($module === FiscalModuleKey::Declarations) {
            $obligationCode = $this->declarationsObligationCode($filters->submodule);
            if ($obligationCode !== null) {
                $escaped = $this->escapeSqlLiteral($obligationCode);

                return DB::query()->selectRaw(<<<SQL
(
    SELECT top.last_valid_query_at
    FROM tax_obligation_projections top
    INNER JOIN tax_obligation_definitions tod
      ON tod.id = top.obligation_definition_id
    WHERE top.tenant_id = {$tenant->id}
      AND top.client_id = clients.id
      AND tod.code = '{$escaped}'
      AND top.last_valid_query_at IS NOT NULL
    ORDER BY top.last_valid_query_at DESC, top.id DESC LIMIT 1
)
SQL);
            }
            if ($submodule === 'FGTS') {
                return DB::query()->selectRaw(<<<SQL
(
    SELECT fcs.last_synced_at
    FROM fgts_competence_statuses fcs
    WHERE fcs.tenant_id = {$tenant->id}
      AND fcs.client_id = clients.id
      AND fcs.is_quarantined = false
      AND fcs.last_synced_at IS NOT NULL
    ORDER BY fcs.last_synced_at DESC, fcs.id DESC LIMIT 1
)
SQL);
            }
        }

        $systemCodes = $this->systemCodesForModule($module, $filters->submodule);
        $sysList = $this->quoteList($systemCodes);

        return DB::query()->selectRaw(<<<SQL
(
    SELECT fs.observed_at FROM fiscal_snapshots fs
    WHERE fs.tenant_id = {$tenant->id}
      AND fs.client_id = clients.id
      AND fs.is_current = true
      AND fs.system_code IN ({$sysList})
    ORDER BY fs.observed_at DESC, fs.id DESC LIMIT 1
)
SQL);
    }

    private function competenceSubquery(
        Tenant $tenant,
        FiscalModuleKey $module,
        ModulePortfolioFilters $filters,
    ): QueryBuilder {
        $catIds = $this->categoryIdsForModule($module, $filters->submodule);
        $catList = $catIds === [] ? 'NULL' : implode(',', array_map('intval', $catIds));

        return DB::query()->selectRaw(<<<SQL
(
    SELECT fc.period_key FROM fiscal_competences fc
    WHERE fc.tenant_id = {$tenant->id}
      AND fc.client_id = clients.id
      AND (fc.fiscal_category_id IN ({$catList}) OR fc.fiscal_category_id IS NULL)
    ORDER BY fc.period_year DESC,
             CASE WHEN fc.period_month IS NULL THEN 1 ELSE 0 END ASC,
             fc.period_month DESC, fc.id DESC
    LIMIT 1
)
SQL);
    }

    /**
     * @param  Builder<Client>  $q
     */
    private function applySearch(Builder $q, string $search): void
    {
        $needle = '%'.mb_strtolower($search).'%';
        $cnpjNeedle = '%'.strtoupper(Cnpj::normalize($search)).'%';

        $q->where(function (Builder $inner) use ($needle, $cnpjNeedle): void {
            $inner->whereRaw('LOWER(clients.legal_name) LIKE ?', [$needle])
                ->orWhereRaw('LOWER(COALESCE(clients.display_name, \'\')) LIKE ?', [$needle])
                ->orWhere('clients.root_cnpj', 'like', $cnpjNeedle)
                ->orWhereExists(function (QueryBuilder $est) use ($cnpjNeedle): void {
                    $est->select(DB::raw('1'))
                        ->from('establishments')
                        ->whereColumn('establishments.client_id', 'clients.id')
                        ->where('establishments.cnpj', 'like', $cnpjNeedle);
                });
        });
    }

    /**
     * @return Collection<int, FiscalCategory>
     */
    private function moduleCategories(FiscalModuleKey $module): Collection
    {
        $flag = $module->featureFlagKey();
        if ($flag === null) {
            return collect();
        }

        return FiscalCategory::query()
            ->where('module_key', $flag)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * @return list<int>
     */
    private function categoryIdsForModule(FiscalModuleKey $module, ?string $submodule): array
    {
        $flag = $module->featureFlagKey();
        if ($flag === null) {
            return [];
        }

        $q = FiscalCategory::query()
            ->where('module_key', $flag)
            ->where('is_active', true);

        if ($submodule !== null) {
            $code = $this->normalizeSubmoduleToCategoryCode($module, $submodule);
            $service = $this->normalizeSubmoduleToServiceCode($module, $submodule);
            $q->where(function ($inner) use ($code, $service, $submodule): void {
                $inner->where('code', $code)
                    ->orWhere('code', $submodule)
                    ->orWhere('service_code', $service)
                    ->orWhere('service_code', $submodule);
            });
        }

        return $q->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * @return list<string>
     */
    private function systemCodesForModule(FiscalModuleKey $module, ?string $submodule): array
    {
        $flag = $module->featureFlagKey();
        if ($flag === null) {
            return ['__none__'];
        }

        $q = FiscalCategory::query()
            ->where('module_key', $flag)
            ->where('is_active', true)
            ->whereNotNull('system_code');

        if ($submodule !== null) {
            $ids = $this->categoryIdsForModule($module, $submodule);
            if ($ids !== []) {
                $q->whereIn('id', $ids);
            }
        }

        $codes = $q->pluck('system_code')->filter()->unique()->values()->all();

        return $codes !== [] ? $codes : ['__none__'];
    }

    /**
     * @return list<string>
     */
    private function serviceCodesForModule(FiscalModuleKey $module, ?string $submodule): array
    {
        $flag = $module->featureFlagKey();
        if ($flag === null) {
            return [];
        }

        $q = FiscalCategory::query()
            ->where('module_key', $flag)
            ->where('is_active', true)
            ->whereNotNull('service_code');

        if ($submodule !== null) {
            $ids = $this->categoryIdsForModule($module, $submodule);
            if ($ids !== []) {
                $q->whereIn('id', $ids);
            }
        }

        return $q->pluck('service_code')->filter()->unique()->values()->all();
    }

    private function normalizeSubmoduleToCategoryCode(FiscalModuleKey $module, string $submodule): string
    {
        return match ($module) {
            FiscalModuleKey::SimplesMei => match ($submodule) {
                'PGDASD' => 'SIMPLES_NACIONAL',
                'PGMEI' => 'MEI',
                default => $submodule,
            },
            // Abas de obrigação do hub usam a categoria agregada DECLARACOES.
            FiscalModuleKey::Declarations => match (strtoupper($submodule)) {
                'PGDAS', 'PGDASD', 'DEFIS', 'DASN_SIMEI', 'DASNSIMEI',
                'DCTFWEB', 'DCTF', 'MIT', 'FGTS', 'DIRF', 'DECLARACOES' => 'DECLARACOES',
                default => $submodule,
            },
            default => $submodule,
        };
    }

    private function normalizeSubmoduleToServiceCode(FiscalModuleKey $module, string $submodule): string
    {
        return match ($module) {
            FiscalModuleKey::SimplesMei => match ($submodule) {
                'PGDASD' => 'PGDASD',
                'PGMEI' => 'PGMEI',
                default => $submodule,
            },
            default => $submodule,
        };
    }

    /**
     * @param  Collection<int, FiscalCategory>  $categories
     */
    private function moduleDefaultCoverage(Collection $categories): ?string
    {
        if ($categories->isEmpty()) {
            return FiscalCoverage::Unknown->value;
        }

        return $this->coverageAggregator->aggregate(
            $categories->map(fn (FiscalCategory $category) => $category->default_coverage),
        )->value;
    }

    /**
     * Descritores de documento por cliente — href só com artefato real do CurrentTenant.
     *
     * @param  list<int>  $clientIds
     * @return array<int, FiscalDocumentDescriptorDto>
     */
    private function loadDocuments(
        Tenant $tenant,
        FiscalModuleKey $module,
        ModulePortfolioFilters $filters,
        array $clientIds,
    ): array {
        if ($clientIds === []) {
            return [];
        }

        $surface = $this->surfaces->resolveForModule($module, $filters->submodule);
        $artifactsByClient = $this->latestArtifactsByClient($tenant, $clientIds, $surface);
        $map = [];
        foreach ($clientIds as $cid) {
            $map[$cid] = $this->documentDescriptors->forSurface(
                $tenant,
                $surface,
                $artifactsByClient[$cid] ?? null,
            );
        }

        return $map;
    }

    /**
     * @param  list<int>  $clientIds
     * @return array<int, FiscalEvidenceArtifact>
     */
    private function latestArtifactsByClient(
        Tenant $tenant,
        array $clientIds,
        MonitoringSurfaceContract $surface,
    ): array {
        $opKeys = $surface->operationKeys;

        $rows = FiscalEvidenceArtifact::query()
            ->withoutGlobalScopes()
            ->from('fiscal_evidence_artifacts as fea')
            ->join('fiscal_monitoring_runs as fmr', 'fmr.id', '=', 'fea.run_id')
            ->where(function ($query): void {
                $query->whereNull('fea.verification_state')
                    ->orWhere('fea.verification_state', '!=', 'REJECTED');
            })
            ->where('fea.tenant_id', $tenant->id)
            ->whereIn('fmr.client_id', $clientIds)
            ->when(
                $opKeys !== [],
                function ($q) use ($opKeys): void {
                    $q->where(function ($inner) use ($opKeys): void {
                        $inner->whereIn('fea.operation_key', $opKeys)
                            ->orWhereIn('fmr.operation_key', $opKeys);
                    });
                },
            )
            ->orderByDesc('fea.observed_at')
            ->orderByDesc('fea.id')
            ->select(['fea.*', 'fmr.client_id as portfolio_client_id'])
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $cid = (int) $row->getAttribute('portfolio_client_id');
            if ($cid < 1 || isset($map[$cid])) {
                continue;
            }
            if ((int) $row->tenant_id !== (int) $tenant->id) {
                continue;
            }
            $map[$cid] = $row;
        }

        return $map;
    }

    /**
     * @param  list<int>  $clientIds
     * @return array<int, string>
     */
    private function loadMatrixCnpjs(Tenant $tenant, array $clientIds): array
    {
        if ($clientIds === []) {
            return [];
        }

        $rows = DB::table('establishments')
            ->where('tenant_id', $tenant->id)
            ->whereIn('client_id', $clientIds)
            ->orderByDesc('is_headquarters')
            ->orderBy('id')
            ->get(['client_id', 'cnpj', 'is_headquarters']);

        $map = [];
        foreach ($rows as $row) {
            $cid = (int) $row->client_id;
            if (! isset($map[$cid])) {
                $map[$cid] = (string) $row->cnpj;
            }
        }

        return $map;
    }

    /**
     * @param  list<int>  $clientIds
     * @return array<int, string>
     */
    private function loadCoverages(
        Tenant $tenant,
        FiscalModuleKey $module,
        array $clientIds,
        ModulePortfolioFilters $filters,
    ): array {
        if ($clientIds === []) {
            return [];
        }

        $systemCodes = $this->systemCodesForModule($module, $filters->submodule);
        $rows = FiscalSnapshot::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('is_current', true)
            ->whereIn('client_id', $clientIds)
            ->whereIn('system_code', $systemCodes)
            ->orderByDesc('observed_at')
            ->get(['client_id', 'coverage']);

        $map = [];
        foreach ($rows->groupBy('client_id') as $clientId => $clientRows) {
            $map[(int) $clientId] = $this->coverageAggregator->aggregate(
                $clientRows->map(fn (FiscalSnapshot $snapshot) => $snapshot->coverage),
            )->value;
        }

        // Cobertura do vínculo quando não há snapshot
        $linkCoverages = TenantFiscalCategoryLink::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereIn('client_id', $clientIds)
            ->where('status', FiscalLinkStatus::Active->value)
            ->whereIn('fiscal_category_id', $this->categoryIdsForModule($module, $filters->submodule))
            ->get(['client_id', 'coverage']);

        foreach ($linkCoverages->groupBy('client_id') as $clientId => $links) {
            $cid = (int) $clientId;
            if (! isset($map[$cid])) {
                $map[$cid] = $this->coverageAggregator->aggregate(
                    $links->map(fn (TenantFiscalCategoryLink $link) => $link->coverage),
                )->value;
            }
        }

        return $map;
    }

    /**
     * @param  list<int>  $clientIds
     * @return array<int, array<string, mixed>>
     */
    private function loadModuleDetails(
        Tenant $tenant,
        FiscalModuleKey $module,
        array $clientIds,
        ModulePortfolioFilters $filters,
    ): array {
        if ($clientIds === []) {
            return [];
        }

        return match ($module) {
            FiscalModuleKey::SimplesMei => $this->detailSimplesMei($tenant, $clientIds, $filters),
            FiscalModuleKey::Dctfweb => $this->detailDctfwebMit($tenant, $clientIds, $filters),
            FiscalModuleKey::Installments => $this->detailParcelamentos($tenant, $clientIds, $filters),
            FiscalModuleKey::Sitfis => $this->detailSitfis($tenant, $clientIds),
            FiscalModuleKey::Mailbox => $this->detailMailbox($tenant, $clientIds),
            FiscalModuleKey::Declarations => $this->detailDeclaracoes($tenant, $clientIds, $filters),
            FiscalModuleKey::Guides => $this->detailGuias($tenant, $clientIds),
            FiscalModuleKey::Fgts => $this->detailFgts($tenant, $clientIds),
        };
    }

    /**
     * @param  list<int>  $clientIds
     * @return array<int, array{due_at: ?string}>
     */
    private function loadNextDeadlines(
        Tenant $tenant,
        FiscalModuleKey $module,
        array $clientIds,
        ModulePortfolioFilters $filters,
    ): array {
        if ($clientIds === []) {
            return [];
        }

        $catIds = $this->categoryIdsForModule($module, $filters->submodule);
        $rows = FiscalCompetence::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereIn('client_id', $clientIds)
            ->whereNotNull('due_at')
            ->whereNull('closed_at')
            ->when($catIds !== [], fn ($q) => $q->where(function ($inner) use ($catIds): void {
                $inner->whereIn('fiscal_category_id', $catIds)->orWhereNull('fiscal_category_id');
            }))
            ->orderBy('due_at')
            ->get(['client_id', 'due_at']);

        $map = [];
        foreach ($rows as $row) {
            $cid = (int) $row->client_id;
            if (! isset($map[$cid])) {
                $map[$cid] = ['due_at' => $row->due_at?->toIso8601String()];
            }
        }

        return $map;
    }

    /**
     * @param  list<int>  $clientIds
     * @return array<int, array<string, mixed>>
     */
    private function detailSimplesMei(Tenant $tenant, array $clientIds, ModulePortfolioFilters $filters): array
    {
        $comps = FiscalCompetence::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereIn('client_id', $clientIds)
            ->orderByDesc('period_year')
            ->orderByDesc('period_month')
            ->get();

        $sub = strtoupper((string) ($filters->submodule ?? 'PGDASD'));
        $pgdasdDetails = [];
        $pgmeiDetails = [];
        if (in_array($sub, ['PGDASD', ''], true)) {
            $pgdasdDetails = app(PgdasdMonitoringQueryService::class)
                ->portfolioDetails($tenant, $clientIds);
        }
        if ($sub === 'PGMEI') {
            $pgmeiDetails = app(PgmeiMonitoringQueryService::class)
                ->portfolioDetails($tenant, $clientIds, $filters->year);
        }

        $procuracaoByClient = $this->simplesMeiProcuracaoProjections($tenant, $clientIds);

        $map = [];
        foreach ($clientIds as $cid) {
            $comp = $comps->firstWhere('client_id', $cid);
            $procuracao = $procuracaoByClient[$cid] ?? [
                'status' => 'unverified',
                'valid_to' => null,
                'checked_at' => null,
            ];
            $base = [
                'module_key' => FiscalModuleKey::SimplesMei->value,
                'submodule' => $filters->submodule,
                'period_key' => $comp?->period_key,
                'competence_id' => $comp?->id,
                'procuracao_status' => $procuracao['status'],
                'procuracao_valid_to' => $procuracao['valid_to'],
                'procuracao_checked_at' => $procuracao['checked_at'],
                'links' => [
                    'regimes' => "/api/v1/fiscal/simples-mei/clients/{$cid}/regimes",
                    'competences' => "/api/v1/fiscal/simples-mei/clients/{$cid}/competences",
                    'snapshots' => "/api/v1/fiscal/simples-mei/clients/{$cid}/snapshots",
                ],
            ];

            if (isset($pgdasdDetails[$cid])) {
                $pg = $pgdasdDetails[$cid];
                $base['pgdasd'] = [
                    'expected_period_key' => $pg['expected_period_key'] ?? null,
                    'latest_declaration' => $pg['latest_declaration'] ?? null,
                    'declaration_state' => $pg['declaration_state'] ?? null,
                    'declaration_state_reason' => $pg['declaration_state_reason'] ?? null,
                    'payment_state' => $pg['payment_state'] ?? null,
                    'payment_state_reason' => $pg['payment_state_reason'] ?? null,
                    'payment_das_count' => $pg['payment_das_count'] ?? null,
                    'payment_unpaid_count' => $pg['payment_unpaid_count'] ?? null,
                    'payment_paid_count' => $pg['payment_paid_count'] ?? null,
                    'payment_open_competencies' => $pg['payment_open_competencies'] ?? [],
                    'last_valid_query_at' => $pg['last_valid_query_at'] ?? null,
                    'rbt12' => $pg['rbt12'] ?? null,
                    'documents' => $pg['documents'] ?? [],
                    'communication' => $pg['communication'] ?? null,
                ];
                $base['links'] = array_merge($base['links'], $pg['links'] ?? []);
            }

            if (isset($pgmeiDetails[$cid])) {
                $pm = $pgmeiDetails[$cid];
                $base['pgmei'] = $pm['pgmei'] ?? null;
                $base['links'] = array_merge($base['links'], $pm['links'] ?? []);
            }

            $map[$cid] = $base;
        }

        return $map;
    }

    /**
     * Projeção oficial de procuração e-CAC por cliente (sem egress SERPRO).
     *
     * @param  list<int>  $clientIds
     * @return array<int, array{status: string, valid_to: ?string, checked_at: ?string}>
     */
    private function simplesMeiProcuracaoProjections(Tenant $tenant, array $clientIds): array
    {
        if ($clientIds === []) {
            return [];
        }

        $resolver = app(ClientProcuracaoValidityResolver::class);
        $environment = (string) config('serpro.default_environment', 'TRIAL');

        $syncs = ClientProcuracaoSync::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereIn('client_id', $clientIds)
            ->where('environment', $environment)
            ->get()
            ->keyBy('client_id');

        $out = [];
        foreach ($clientIds as $cid) {
            $out[$cid] = $resolver->resolve($syncs->get($cid));
        }

        return $out;
    }

    /**
     * @param  list<int>  $clientIds
     * @return array<int, array<string, mixed>>
     */
    private function detailDctfwebMit(Tenant $tenant, array $clientIds, ModulePortfolioFilters $filters): array
    {
        $sub = strtoupper((string) ($filters->submodule ?? 'DCTFWEB'));
        $dctfwebDetails = [];
        if (in_array($sub, ['DCTFWEB', 'DCTF', ''], true)) {
            $dctfwebDetails = app(DctfwebMonitoringQueryService::class)
                ->portfolioDetails($tenant, $clientIds);
        }

        $mits = MitAssessment::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereIn('client_id', $clientIds)
            ->orderByDesc('id')
            ->get();

        $map = [];
        foreach ($clientIds as $cid) {
            $mit = $mits->firstWhere('client_id', $cid);
            $base = [
                'module_key' => FiscalModuleKey::Dctfweb->value,
                'submodule' => $filters->submodule,
                'mit' => $mit === null ? null : [
                    'id' => $mit->id,
                    'period_key' => $mit->period_key,
                    'encerramento_status' => $mit->encerramento_status?->value,
                    'dctfweb_transmission_status' => $mit->dctfweb_transmission_status?->value,
                    'situation' => $mit->situation?->value,
                ],
                'links' => [
                    'declarations' => '/api/v1/fiscal/dctfweb/declarations?client_id='.$cid,
                    'mit' => '/api/v1/fiscal/mit/apuracoes?client_id='.$cid,
                ],
            ];

            if (isset($dctfwebDetails[$cid])) {
                $d = $dctfwebDetails[$cid];
                $latest = $d['latest_declaration'] ?? null;
                $base['dctfweb'] = [
                    'id' => is_array($latest) ? ($latest['id'] ?? null) : null,
                    'expected_period_key' => $d['expected_period_key'] ?? null,
                    'category' => $d['category'] ?? null,
                    'declaration_state' => $d['declaration_state'] ?? null,
                    'declaration_state_reason' => $d['declaration_state_reason'] ?? null,
                    'latest_declaration' => $latest,
                    'last_search_at' => $d['last_search_at'] ?? null,
                    'calendar_verified' => $d['calendar_verified'] ?? false,
                    'communication' => $d['communication'] ?? null,
                    'has_history' => $d['has_history'] ?? false,
                    'has_tracking' => $d['has_tracking'] ?? false,
                ];
                $base['links'] = array_merge($base['links'], $d['links'] ?? []);
            } else {
                $base['dctfweb'] = null;
            }

            $map[$cid] = $base;
        }

        return $map;
    }

    /**
     * @param  list<int>  $clientIds
     * @return array<int, array<string, mixed>>
     */
    private function detailParcelamentos(
        Tenant $tenant,
        array $clientIds,
        ModulePortfolioFilters $filters,
    ): array {
        $modalities = $filters->modalityList();
        $orders = TaxInstallmentOrder::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereIn('client_id', $clientIds)
            ->when($modalities !== [], fn ($query) => $query->whereIn('modality', $modalities))
            ->orderByDesc('observed_at')
            ->orderByDesc('id')
            ->get();

        $parcels = TaxInstallmentParcel::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereIn('client_id', $clientIds)
            ->when($modalities !== [], fn ($query) => $query->whereIn('modality', $modalities))
            ->whereNotIn('status', [TaxInstallmentParcelStatus::Paid->value, TaxInstallmentParcelStatus::Cancelled->value])
            ->orderBy('due_at')
            ->get();

        $map = [];
        foreach ($clientIds as $cid) {
            $clientOrders = $orders->where('client_id', $cid)->values();
            $clientParcels = $parcels->where('client_id', $cid)->values();
            $order = $clientOrders->first();
            $nextParcel = $clientParcels->first();
            $overdue = $clientParcels
                ->filter(fn (TaxInstallmentParcel $p) => in_array(
                    $p->status,
                    [TaxInstallmentParcelStatus::Attention, TaxInstallmentParcelStatus::Pending],
                    true,
                ))
                ->count();
            $orderModalities = $clientOrders
                ->map(fn (TaxInstallmentOrder $item): string => $item->modality?->value
                    ?? (string) $item->getAttribute('modality'))
                ->filter()
                ->unique()
                ->sort()
                ->values()
                ->all();
            $modalityQuery = $modalities === [] ? '' : '&modality='.urlencode(implode(',', $modalities));

            $map[$cid] = [
                'module_key' => FiscalModuleKey::Installments->value,
                'order_id' => $order?->id,
                'modality' => $order?->modality?->value ?? $order?->getAttribute('modality'),
                'modalities' => $orderModalities,
                'order_count' => $clientOrders->count(),
                'orders' => $clientOrders->map(
                    fn (TaxInstallmentOrder $item): array => $item->toPublicArray()
                )->all(),
                'external_order_id' => $order?->external_order_id,
                'total_amount_cents' => $clientOrders->sum('total_amount_cents'),
                'parcel_count' => $clientOrders->sum('parcel_count'),
                'order_situation' => $order?->situation,
                'next_parcel_id' => $nextParcel?->id,
                'next_parcel_due_at' => $nextParcel?->due_at?->toIso8601String(),
                'next_parcel_amount_cents' => $nextParcel?->amount_cents,
                'overdue_parcels' => $overdue,
                'links' => [
                    'orders' => '/api/v1/fiscal/installments/orders?client_id='.$cid.$modalityQuery,
                    'parcels' => '/api/v1/fiscal/installments/parcels?client_id='.$cid.$modalityQuery,
                    'order' => $order !== null
                        ? '/api/v1/fiscal/installments/orders/'.$order->id
                        : null,
                ],
            ];
        }

        return $map;
    }

    /**
     * @param  list<int>  $clientIds
     * @return array<int, array<string, mixed>>
     */
    private function detailSitfis(Tenant $tenant, array $clientIds): array
    {
        $system = (string) config('fiscal_monitoring.sitfis.system_code', 'INTEGRA_SITFIS');
        $ttl = (int) config('fiscal_monitoring.sitfis.snapshot_ttl_seconds', 86400);

        $snapshots = FiscalSnapshot::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('is_current', true)
            ->where('system_code', $system)
            ->whereIn('client_id', $clientIds)
            ->orderByDesc('observed_at')
            ->get();

        $snapshotIds = $snapshots->pluck('id')->map(fn ($id): int => (int) $id)->all();

        $findings = FiscalFinding::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereIn('client_id', $clientIds)
            ->whereIn('snapshot_id', $snapshotIds)
            ->where('is_active', true)
            ->selectRaw('client_id, COUNT(*) as cnt')
            ->groupBy('client_id')
            ->pluck('cnt', 'client_id');

        $pending = FiscalPendingItem::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereIn('client_id', $clientIds)
            ->where('status', 'OPEN')
            ->whereHas('run', fn ($query) => $query
                ->withoutGlobalScopes()
                ->where('system_code', $system)
                ->where('service_code', (string) config('fiscal_monitoring.sitfis.service_code', 'SITFIS')))
            ->selectRaw('client_id, COUNT(*) as cnt')
            ->groupBy('client_id')
            ->pluck('cnt', 'client_id');

        $comms = app(SitfisCommunicationService::class)
            ->summariesForClients($tenant, $clientIds);

        $map = [];
        foreach ($clientIds as $cid) {
            $snap = $snapshots->firstWhere('client_id', $cid);
            $observed = $snap?->observed_at;
            $ageSeconds = $observed !== null ? $observed->diffInSeconds(now()) : null;
            $expired = $observed !== null && $ageSeconds !== null && $ageSeconds > $ttl;

            $map[$cid] = [
                'module_key' => FiscalModuleKey::Sitfis->value,
                'snapshot_id' => $snap?->id,
                'observed_at' => $observed?->toIso8601String(),
                'age_seconds' => $ageSeconds,
                'ttl_seconds' => $ttl,
                'is_expired' => $expired,
                'findings_count' => (int) ($findings[$cid] ?? 0),
                'pending_count' => (int) ($pending[$cid] ?? 0),
                'communication' => $comms[$cid] ?? null,
                'links' => [
                    'sitfis' => '/api/v1/fiscal/sitfis?client_id='.$cid,
                    'findings' => '/api/v1/fiscal/findings?client_id='.$cid,
                    'pending_items' => '/api/v1/fiscal/pending-items?client_id='.$cid,
                    'snapshot' => $snap !== null ? '/api/v1/fiscal/snapshots/'.$snap->id : null,
                ],
            ];
        }

        return $map;
    }

    /**
     * @param  list<int>  $clientIds
     * @return array<int, array<string, mixed>>
     */
    private function detailMailbox(Tenant $tenant, array $clientIds): array
    {
        $states = MailboxContributorState::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereIn('client_id', $clientIds)
            ->get()
            ->keyBy('client_id');

        $latest = MailboxMessage::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereIn('client_id', $clientIds)
            ->orderByDesc('received_at_official')
            ->get()
            ->groupBy('client_id');

        $openCounts = MailboxMessage::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereIn('client_id', $clientIds)
            ->whereIn('triage_status', ['NEW', 'IN_REVIEW'])
            ->selectRaw('client_id, COUNT(*) as cnt')
            ->groupBy('client_id')
            ->pluck('cnt', 'client_id');

        $map = [];
        foreach ($clientIds as $cid) {
            /** @var MailboxContributorState|null $state */
            $state = $states->get($cid);
            /** @var MailboxMessage|null $msg */
            $msg = $latest->get($cid)?->first();

            $map[$cid] = [
                'module_key' => FiscalModuleKey::Mailbox->value,
                'official_unread_count' => $state?->official_unread_count ?? 0,
                'stored_message_count' => $state?->stored_message_count ?? 0,
                'open_triage_count' => (int) ($openCounts[$cid] ?? 0),
                'dte_status' => $state?->dte_status?->value,
                'latest_message_id' => $msg?->id,
                'latest_subject_preview' => $msg?->subject_preview,
                'latest_received_at' => $msg?->received_at_official?->toIso8601String(),
                'latest_due_at' => $msg?->due_at?->toIso8601String(),
                'links' => [
                    'messages' => '/api/v1/fiscal/mailbox/messages?client_id='.$cid,
                    'state' => '/api/v1/fiscal/mailbox/state?client_id='.$cid,
                    'message' => $msg !== null
                        ? '/api/v1/fiscal/mailbox/messages/'.$msg->id
                        : null,
                ],
            ];
        }

        return $map;
    }

    /**
     * Escopo do hub Declarações por aba (obrigação / origem / unsupported).
     *
     * @param  Builder<Client>|QueryBuilder  $q
     */
    private function applyDeclarationsSubmoduleScope(
        Builder|QueryBuilder $q,
        Tenant $tenant,
        ModulePortfolioFilters $filters,
    ): void {
        $sub = strtoupper((string) ($filters->submodule ?? ''));
        if ($sub === '' || $sub === 'DECLARACOES') {
            return;
        }

        if ($sub === 'DIRF') {
            // Sem catálogo/API: carteira vazia honesta (UI marca UNSUPPORTED).
            $q->whereRaw('1 = 0');

            return;
        }

        if ($sub === 'FGTS') {
            $q->whereExists(function (QueryBuilder $exists) use ($tenant): void {
                $exists->select(DB::raw('1'))
                    ->from('fgts_competence_statuses as fcs')
                    ->whereColumn('fcs.client_id', 'clients.id')
                    ->where('fcs.tenant_id', $tenant->id)
                    ->where('fcs.is_quarantined', false);
            });

            return;
        }

        $obligationCode = $this->declarationsObligationCode($filters->submodule);
        if ($obligationCode === null) {
            return;
        }

        $q->whereExists(function (QueryBuilder $exists) use ($tenant, $obligationCode): void {
            $exists->select(DB::raw('1'))
                ->from('tax_obligation_projections as top')
                ->join('tax_obligation_definitions as tod', 'tod.id', '=', 'top.obligation_definition_id')
                ->whereColumn('top.client_id', 'clients.id')
                ->where('top.tenant_id', $tenant->id)
                ->where('tod.code', $obligationCode);
        });
    }

    /**
     * Escopo de carteira Simples/MEI por família de tax_regime (PGDASD≠PGMEI).
     * Sem submodule conhecido → carteira vazia (fail-closed); regimes fora de SN/MEI não entram.
     */
    private function applySimplesMeiSubmoduleScope(
        Builder|QueryBuilder $q,
        ModulePortfolioFilters $filters,
    ): void {
        $sub = strtoupper(trim((string) ($filters->submodule ?? '')));
        $regime = match ($sub) {
            'PGDASD', 'PGDAS', 'SIMPLES', 'SIMPLES_NACIONAL' => TaxRegimeCode::SimplesNacional,
            'PGMEI', 'MEI' => TaxRegimeCode::Mei,
            default => null,
        };

        if ($regime === null) {
            $q->whereRaw('1 = 0');

            return;
        }

        $q->where('clients.tax_regime', $regime->value);
    }

    /**
     * Filtro Enviado / Não enviado (comunicação PGDAS-D).
     * Alinhado a PgdasdCommunicationService::trackingStatus: qualquer dispatch
     * = Enviado; ausência = Não enviado (NO_HISTORY / NOT_CONFIGURED).
     * Só aplica em submodule PGDASD; PGMEI ignora send_status.
     */
    private function applyPgdasdSendStatusFilter(
        Builder|QueryBuilder $q,
        Tenant $tenant,
        ModulePortfolioFilters $filters,
    ): void {
        $statuses = $filters->sendStatusList();
        if ($statuses === []) {
            return;
        }

        $sub = strtoupper(trim((string) ($filters->submodule ?? '')));
        if (! in_array($sub, ['PGDASD', 'PGDAS', 'SIMPLES', 'SIMPLES_NACIONAL', ''], true)) {
            return;
        }

        $wantSent = in_array('sent', $statuses, true);
        $wantNotSent = in_array('not_sent', $statuses, true);
        if ($wantSent && $wantNotSent) {
            return;
        }

        $moduleKey = PgdasdCommunicationService::MODULE;
        $submoduleKey = PgdasdCommunicationService::SUBMODULE;

        $existsDispatch = function (QueryBuilder $exists) use ($tenant, $moduleKey, $submoduleKey): void {
            $exists->select(DB::raw('1'))
                ->from('client_communication_dispatches as ccd')
                ->whereColumn('ccd.client_id', 'clients.id')
                ->where('ccd.tenant_id', $tenant->id)
                ->where('ccd.module_key', $moduleKey)
                ->where('ccd.submodule_key', $submoduleKey);
        };

        if ($wantSent) {
            $q->whereExists($existsDispatch);

            return;
        }

        if ($wantNotSent) {
            $q->whereNotExists($existsDispatch);
        }
    }

    /**
     * Mapeia submodule da aba → código de obrigação do catálogo.
     * DIRF/FGTS/agregado retornam null (tratamento especial ou sem filtro).
     */
    private function declarationsObligationCode(?string $submodule): ?string
    {
        return match (strtoupper(trim((string) $submodule))) {
            'PGDAS', 'PGDASD' => 'PGDAS_D',
            'DEFIS' => 'DEFIS',
            'DASN_SIMEI', 'DASNSIMEI' => 'DASN_SIMEI',
            'DCTFWEB', 'DCTF' => 'DCTFWEB',
            'MIT' => 'MIT',
            default => null,
        };
    }

    private function escapeSqlLiteral(string $value): string
    {
        return str_replace("'", "''", $value);
    }

    /**
     * @param  list<int>  $clientIds
     * @return array<int, array<string, mixed>>
     */
    private function detailDeclaracoes(Tenant $tenant, array $clientIds, ModulePortfolioFilters $filters): array
    {
        $sub = strtoupper((string) ($filters->submodule ?? ''));

        if ($sub === 'DIRF') {
            $map = [];
            foreach ($clientIds as $cid) {
                $map[$cid] = [
                    'module_key' => FiscalModuleKey::Declarations->value,
                    'submodule' => 'DIRF',
                    'open_count' => 0,
                    'next_projection_id' => null,
                    'next_obligation_code' => 'DIRF',
                    'next_period_key' => null,
                    'next_due_at' => null,
                    'next_delivery_status' => null,
                    'next_situation' => FiscalSituation::Unsupported->value,
                    'partial_coverage_notice' => 'DIRF não possui cobertura de catálogo nesta superfície.',
                    'links' => [
                        'declarations' => '/api/v1/fiscal/declarations?client_id='.$cid,
                    ],
                ];
            }

            return $map;
        }

        if ($sub === 'FGTS') {
            $fgtsDetails = $this->detailFgts($tenant, $clientIds);
            $map = [];
            foreach ($clientIds as $cid) {
                $fgts = $fgtsDetails[$cid] ?? null;
                $map[$cid] = [
                    'module_key' => FiscalModuleKey::Declarations->value,
                    'submodule' => 'FGTS',
                    'open_count' => 0,
                    'next_projection_id' => null,
                    'next_obligation_code' => 'FGTS',
                    'next_period_key' => $fgts['competence_period_key'] ?? null,
                    'next_due_at' => null,
                    'next_delivery_status' => $fgts['closure_status'] ?? null,
                    'next_situation' => null,
                    'fgts' => $fgts,
                    'partial_coverage_notice' => $fgts['partial_coverage_notice']
                        ?? 'Cobertura parcial FGTS: sem inventário de guia/pagamento nesta aba.',
                    'links' => [
                        'declarations' => '/api/v1/fiscal/declarations?client_id='.$cid,
                        'fgts' => '/api/v1/fiscal/fgts?client_id='.$cid,
                    ],
                ];
            }

            return $map;
        }

        $obligationCode = $this->declarationsObligationCode($filters->submodule);

        $projections = TaxObligationProjection::query()
            ->withoutGlobalScopes()
            ->with('obligation')
            ->where('tenant_id', $tenant->id)
            ->whereIn('client_id', $clientIds)
            ->where('is_open', true)
            ->when(
                $obligationCode !== null,
                fn ($q) => $q->whereHas('obligation', fn ($qq) => $qq->where('code', $obligationCode)),
            )
            ->orderBy('due_at')
            ->get()
            ->groupBy('client_id');

        $pgdasdDetails = [];
        if (in_array($sub, ['PGDAS', 'PGDASD'], true)) {
            $pgdasdDetails = app(PgdasdMonitoringQueryService::class)
                ->portfolioDetails($tenant, $clientIds);
        }

        $map = [];
        foreach ($clientIds as $cid) {
            /** @var Collection<int, TaxObligationProjection> $rows */
            $rows = $projections->get($cid) ?? collect();
            $next = $rows->first();
            $pgdasd = $pgdasdDetails[$cid] ?? null;
            $map[$cid] = [
                'module_key' => FiscalModuleKey::Declarations->value,
                'submodule' => $sub !== '' ? $sub : null,
                'open_count' => $rows->count(),
                'next_projection_id' => $next?->id,
                'next_obligation_code' => $next?->obligation?->code ?? $obligationCode,
                'next_period_key' => $next?->period_key ?? ($pgdasd['expected_period_key'] ?? null),
                'next_due_at' => $next?->due_at?->toIso8601String(),
                'next_delivery_status' => $next?->delivery_status?->value,
                'next_situation' => $next?->situation?->value,
                'pgdasd' => $pgdasd === null ? null : [
                    'expected_period_key' => $pgdasd['expected_period_key'] ?? null,
                    'latest_declaration' => $pgdasd['latest_declaration'] ?? null,
                    'declaration_state' => $pgdasd['declaration_state'] ?? null,
                    'declaration_state_reason' => $pgdasd['declaration_state_reason'] ?? null,
                    'payment_state' => $pgdasd['payment_state'] ?? null,
                    'payment_state_reason' => $pgdasd['payment_state_reason'] ?? null,
                    'payment_das_count' => $pgdasd['payment_das_count'] ?? null,
                    'payment_unpaid_count' => $pgdasd['payment_unpaid_count'] ?? null,
                    'payment_paid_count' => $pgdasd['payment_paid_count'] ?? null,
                    'payment_open_competencies' => $pgdasd['payment_open_competencies'] ?? [],
                    'last_valid_query_at' => $pgdasd['last_valid_query_at'] ?? null,
                    'rbt12' => $pgdasd['rbt12'] ?? null,
                    'documents' => $pgdasd['documents'] ?? [],
                    'communication' => $pgdasd['communication'] ?? null,
                ],
                'links' => [
                    'declarations' => '/api/v1/fiscal/declarations?client_id='.$cid
                        .($obligationCode !== null ? '&obligation_code='.$obligationCode : ''),
                    'summary' => '/api/v1/fiscal/declarations/summary?client_id='.$cid,
                    'projection' => $next !== null
                        ? '/api/v1/fiscal/declarations/'.$next->id
                        : null,
                    'pgdasd_history' => in_array($sub, ['PGDAS', 'PGDASD'], true)
                        ? '/api/v1/fiscal/simples-mei/pgdasd/clients/'.$cid.'/history'
                        : null,
                ],
            ];
        }

        return $map;
    }

    /**
     * @param  list<int>  $clientIds
     * @return array<int, array<string, mixed>>
     */
    private function detailGuias(Tenant $tenant, array $clientIds): array
    {
        $guides = TaxGuide::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereIn('client_id', $clientIds)
            ->orderBy('due_at')
            ->get()
            ->groupBy('client_id');

        $map = [];
        foreach ($clientIds as $cid) {
            /** @var Collection<int, TaxGuide> $rows */
            $rows = $guides->get($cid) ?? collect();
            $open = $rows->filter(fn (TaxGuide $g) => ! in_array(
                $g->payment_status?->value,
                ['CONFIRMED'],
                true,
            ));
            $next = $open->sortBy(fn (TaxGuide $g) => $g->due_at?->getTimestamp() ?? PHP_INT_MAX)->first();
            $unpaidCents = $open->sum(fn (TaxGuide $g) => (int) ($g->amount_cents ?? 0));

            $map[$cid] = [
                'module_key' => FiscalModuleKey::Guides->value,
                'guides_count' => $rows->count(),
                'open_count' => $open->count(),
                'unpaid_amount_cents' => $unpaidCents,
                'next_guide_id' => $next?->id,
                'next_due_at' => $next?->due_at?->toIso8601String(),
                'next_amount_cents' => $next?->amount_cents,
                'next_payment_status' => $next?->payment_status?->value,
                'links' => [
                    'guides' => '/api/v1/fiscal/guides?client_id='.$cid,
                    'guide' => $next !== null ? '/api/v1/fiscal/guides/'.$next->id : null,
                ],
            ];
        }

        return $map;
    }

    /**
     * @param  list<int>  $clientIds
     * @return array<int, array<string, mixed>>
     */
    private function detailFgts(Tenant $tenant, array $clientIds): array
    {
        $rows = FgtsCompetenceStatus::query()
            ->withoutGlobalScopes()
            ->operationallyEligible()
            ->where('tenant_id', $tenant->id)
            ->whereIn('client_id', $clientIds)
            ->orderByDesc('last_synced_at')
            ->get()
            ->groupBy('client_id');

        $map = [];
        foreach ($clientIds as $cid) {
            /** @var FgtsCompetenceStatus|null $row */
            $row = $rows->get($cid)?->first();
            $map[$cid] = [
                'module_key' => FiscalModuleKey::Fgts->value,
                'competence_period_key' => $row?->competence_period_key,
                'closure_status' => $row?->closure_status?->value,
                'totalization_status' => $row?->totalization_status?->value,
                'guide_status' => $row?->guide_status?->value ?? 'UNSUPPORTED',
                'payment_status' => $row?->payment_status?->value ?? 'UNSUPPORTED',
                'coverage' => $row?->coverage?->value ?? FiscalCoverage::Partial->value,
                'last_synced_at' => $row?->last_synced_at?->toIso8601String(),
                'partial_coverage_notice' => 'Guia e pagamento FGTS Digital permanecem UNSUPPORTED sem API pública M2M.',
                'links' => [
                    'coverage' => '/api/v1/fiscal/fgts/coverage',
                    'competences' => '/api/v1/fiscal/fgts/competences?client_id='.$cid,
                    'events' => '/api/v1/fiscal/fgts/events?client_id='.$cid,
                    'status' => $row !== null ? '/api/v1/fiscal/fgts/competences/'.$row->id : null,
                ],
            ];
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function nextActionFor(FiscalModuleKey $module, string $situation, array $detail): ?string
    {
        return match ($situation) {
            FiscalSituation::Error->value => 'Investigar erro da última consulta',
            FiscalSituation::Blocked->value => 'Resolver bloqueio operacional',
            FiscalSituation::Attention->value => match ($module) {
                FiscalModuleKey::Installments => 'Revisar parcelas em atraso',
                FiscalModuleKey::Mailbox => 'Tratar mensagens com prazo',
                FiscalModuleKey::Sitfis => 'Revisar pendências SITFIS',
                default => 'Revisar itens em atenção',
            },
            FiscalSituation::Pending->value => 'Concluir pendências',
            FiscalSituation::Processing->value => 'Aguardar processamento',
            FiscalSituation::Unsupported->value => 'Cobertura parcial / sem fonte M2M',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, string|null>
     */
    private function rowLinks(FiscalModuleKey $module, int $clientId, array $detail): array
    {
        $base = [
            'client' => '/api/v1/clients/'.$clientId,
        ];

        $detailLinks = is_array($detail['links'] ?? null) ? $detail['links'] : [];

        return array_merge($base, $detailLinks);
    }

    /**
     * @param  list<string>  $values
     */
    private function quoteList(array $values): string
    {
        if ($values === []) {
            return "'__none__'";
        }

        return implode(',', array_map(
            static fn (string $v) => "'".str_replace("'", "''", $v)."'",
            $values,
        ));
    }
}
