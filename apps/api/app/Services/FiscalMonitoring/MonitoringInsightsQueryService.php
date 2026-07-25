<?php

namespace App\Services\FiscalMonitoring;

use App\DTO\Fiscal\Module\ModulePortfolioFilters;
use App\Enums\FiscalModuleKey;
use App\Models\Client;
use App\Models\FiscalFinding;
use App\Models\FiscalPendingItem;
use App\Models\MailboxAlert;
use App\Models\MailboxMessage;
use App\Models\Office;
use App\Services\Fiscal\Declarations\DeclarationHubQueryService;
use App\Services\FiscalMonitoring\ModulePortfolio\ModulePortfolioQueryService;
use App\Services\Integra\Mailbox\MailboxQueryService;
use Throwable;

/**
 * Agrega read models locais do office para o dashboard de insights.
 * Somente leitura; sem dispatch SERPRO; fail-closed parcial via partial_errors.
 */
final class MonitoringInsightsQueryService
{
    private const RBT12_LIMIT = 24;

    private const PREVIEW_LIMIT = 8;

    private const MAILBOX_SCAN_LIMIT = 200;

    public function __construct(
        private readonly FiscalQueryService $fiscalQueries,
        private readonly ModulePortfolioQueryService $portfolio,
        private readonly DeclarationHubQueryService $declarations,
        private readonly MailboxQueryService $mailbox,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forOffice(Office $office): array
    {
        $partialErrors = [];
        $asOf = now()->toIso8601String();

        $clientsTotal = $this->safeSection(
            'portfolio',
            $partialErrors,
            fn () => Client::query()
                ->withoutGlobalScopes()
                ->where('office_id', $office->id)
                ->count(),
        );
        $pending = $this->safeSection('pending', $partialErrors, fn () => $this->buildPending($office));
        $findingsPreview = $this->safeSection('findings', $partialErrors, fn () => $this->buildFindingsPreview($office));
        $rbt12 = $this->safeSection('rbt12', $partialErrors, fn () => $this->buildRbt12($office));
        $mailbox = $this->safeSection('mailbox', $partialErrors, fn () => $this->buildMailbox($office));
        $notifications = $this->safeSection(
            'notifications',
            $partialErrors,
            fn () => $this->buildNotifications($office, $pending, $findingsPreview),
        );
        $declarationsAbsence = $this->safeSection(
            'declarations_absence',
            $partialErrors,
            fn () => $this->buildDeclarationsAbsence($office),
        );
        $sitfis = $this->safeSection('sitfis', $partialErrors, fn () => $this->buildSitfis($office));
        $obligationsProgress = $this->safeSection(
            'obligations_progress',
            $partialErrors,
            fn () => $this->buildObligationsProgress($office),
        );

        $modulesWithError = null;
        if (is_array($sitfis) && is_array($obligationsProgress)) {
            $modulesWithError = 0;
            if (! ($sitfis['is_synthetic'] ?? false)) {
                $modulesWithError += ((int) ($sitfis['counters']['error'] ?? 0)) > 0 ? 1 : 0;
            }
            foreach ($obligationsProgress as $row) {
                if (($row['is_synthetic'] ?? false) === true) {
                    continue;
                }
                if (($row['coverage'] ?? null) === 'UNSUPPORTED') {
                    continue;
                }
                if (((int) ($row['error'] ?? 0)) > 0) {
                    $modulesWithError++;
                }
            }
        }

        return [
            'as_of' => $asOf,
            'kpis' => [
                'clients_total' => is_int($clientsTotal) ? $clientsTotal : null,
                'pending_open' => is_array($pending) ? (int) ($pending['total'] ?? 0) : null,
                'findings_active' => is_array($findingsPreview) ? (int) ($findingsPreview['total'] ?? 0) : null,
                'modules_with_error' => $modulesWithError,
            ],
            'pending' => $pending,
            'rbt12' => $rbt12,
            'mailbox' => $mailbox,
            'notifications' => $notifications,
            'declarations_absence' => $declarationsAbsence,
            'sitfis' => $sitfis,
            'obligations_progress' => $obligationsProgress,
            'partial_errors' => $partialErrors === [] ? null : array_values(array_unique($partialErrors)),
        ];
    }

    /**
     * @param  list<string>  $partialErrors
     * @param  callable(): mixed  $callback
     */
    private function safeSection(string $section, array &$partialErrors, callable $callback): mixed
    {
        try {
            return $callback();
        } catch (Throwable) {
            $partialErrors[] = $section;

            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPending(Office $office): array
    {
        $page = $this->fiscalQueries->pendingItems($office, self::PREVIEW_LIMIT, null, 'OPEN');
        $items = $page->getCollection()->map(static function (FiscalPendingItem $item): array {
            $row = $item->toPublicArray();
            unset($row['office_id']);

            return $row;
        })->values()->all();

        $bySeverity = FiscalPendingItem::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->where('status', 'OPEN')
            ->selectRaw('severity, COUNT(*) as total')
            ->groupBy('severity')
            ->pluck('total', 'severity')
            ->map(static fn ($v) => (int) $v)
            ->all();

        return [
            'total' => (int) $page->total(),
            'by_severity' => $bySeverity,
            'items' => $items,
        ];
    }

    /**
     * @return array{total: int, items: list<array<string, mixed>>}
     */
    private function buildFindingsPreview(Office $office): array
    {
        $page = $this->fiscalQueries->findings($office, self::PREVIEW_LIMIT, null, true);
        $items = $page->getCollection()->map(static function (FiscalFinding $item): array {
            $row = $item->toPublicArray();
            unset($row['office_id']);

            return $row;
        })->values()->all();

        return [
            'total' => (int) $page->total(),
            'items' => $items,
        ];
    }

    /**
     * @return array{clients: list<array<string, mixed>>}
     */
    private function buildRbt12(Office $office): array
    {
        $filters = new ModulePortfolioFilters(
            page: 1,
            perPage: self::RBT12_LIMIT,
            submodule: 'PGDASD',
            sort: 'legal_name',
            sortDirection: 'asc',
        );
        $page = $this->portfolio->clients($office, FiscalModuleKey::SimplesMei, $filters);
        $clients = [];

        foreach ($page->items() as $row) {
            $arr = $row->toArray();
            $detail = is_array($arr['detail'] ?? null) ? $arr['detail'] : [];
            $rbt12 = $detail['rbt12'] ?? ($detail['pgdasd']['rbt12'] ?? null);
            if (! is_array($rbt12)) {
                continue;
            }
            $status = strtoupper((string) ($rbt12['status'] ?? ''));
            if ($status !== 'PARSED') {
                continue;
            }
            $totalCents = $rbt12['total_cents'] ?? null;
            if ($totalCents === null && isset($rbt12['rbt12_value'])) {
                $totalCents = (int) round(((float) $rbt12['rbt12_value']) * 100);
            }
            if ($totalCents === null) {
                continue;
            }
            $clients[] = [
                'client_id' => (int) $arr['client_id'],
                'display_name' => (string) ($arr['name'] ?? $arr['legal_name'] ?? 'Cliente'),
                'total_cents' => (int) $totalCents,
                'rbt12_value' => $rbt12['rbt12_value'] ?? null,
                'status' => 'PARSED',
                'period_key' => $rbt12['period_key'] ?? null,
            ];
        }

        usort($clients, static fn (array $a, array $b): int => $b['total_cents'] <=> $a['total_cents']);

        return ['clients' => array_values($clients)];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildMailbox(Office $office): array
    {
        $critical = array_map(
            static fn ($c) => strtoupper((string) $c),
            (array) config('fiscal_monitoring.mailbox.critical_categories', ['INTIMACAO', 'NOTIFICACAO', 'COBRANCA', 'URGENTE']),
        );

        $page = $this->mailbox->messages($office, self::MAILBOX_SCAN_LIMIT);
        $important = 0;
        $upToDate = 0;
        $other = 0;
        $othersBreakdown = [];
        $sample = [];

        foreach ($page->items() as $message) {
            if (! $message instanceof MailboxMessage) {
                continue;
            }
            $row = $message->toListArray();
            unset($row['office_id']);

            $triage = strtoupper((string) ($row['triage_status'] ?? ''));
            $category = strtoupper((string) ($row['category_code'] ?? ''));
            $severity = strtolower((string) ($row['severity_hint'] ?? ''));
            $isImportant = $severity === 'high' || in_array($category, $critical, true);
            $isUpToDate = $triage === 'RESOLVED' || ($row['official_read_indicator'] ?? false) === true;

            if ($isImportant && ! $isUpToDate) {
                $important++;
                $bucket = 'important';
            } elseif ($isUpToDate) {
                $upToDate++;
                $bucket = 'up_to_date';
            } else {
                $other++;
                $bucket = 'other';
                $label = is_string($row['category_label'] ?? null) && $row['category_label'] !== ''
                    ? (string) $row['category_label']
                    : ($category !== '' ? $category : 'Sem categoria');
                $othersBreakdown[$label] = ($othersBreakdown[$label] ?? 0) + 1;
            }

            if (count($sample) < self::PREVIEW_LIMIT) {
                $sample[] = [
                    'id' => $row['id'],
                    'client_id' => $row['client_id'],
                    'subject_preview' => $row['subject_preview'],
                    'category_label' => $row['category_label'] ?? null,
                    'received_at_official' => $row['received_at_official'] ?? null,
                    'bucket' => $bucket,
                ];
            }
        }

        arsort($othersBreakdown);
        $breakdown = [];
        foreach (array_slice($othersBreakdown, 0, 5, true) as $label => $count) {
            $breakdown[] = ['label' => (string) $label, 'count' => (int) $count];
        }

        return [
            'buckets' => [
                'important' => $important,
                'up_to_date' => $upToDate,
                'other' => $other,
            ],
            'scanned' => count($page->items()),
            'total_messages' => (int) $page->total(),
            'others_breakdown' => $breakdown,
            'sample' => $sample,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $pending
     * @param  array{total: int, items: list<array<string, mixed>>}|null  $findings
     * @return array{items: list<array<string, mixed>>}
     */
    private function buildNotifications(Office $office, mixed $pending, mixed $findings): array
    {
        $items = [];

        if (is_array($pending)) {
            foreach (($pending['items'] ?? []) as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $items[] = [
                    'id' => 'pending-'.($row['id'] ?? uniqid()),
                    'type' => 'pending',
                    'severity' => $row['severity'] ?? null,
                    'title' => $row['title'] ?? 'Pendência fiscal',
                    'body' => $row['detail'] ?? null,
                    'client_id' => $row['client_id'] ?? null,
                    'occurred_at' => $row['created_at'] ?? $row['due_at'] ?? null,
                ];
            }
        }

        if (is_array($findings)) {
            foreach (($findings['items'] ?? []) as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $items[] = [
                    'id' => 'finding-'.($row['id'] ?? uniqid()),
                    'type' => 'finding',
                    'severity' => $row['severity'] ?? null,
                    'title' => $row['title'] ?? ($row['code'] ?? 'Finding'),
                    'body' => $row['detail'] ?? null,
                    'client_id' => $row['client_id'] ?? null,
                    'occurred_at' => $row['created_at'] ?? null,
                ];
            }
        }

        $alerts = $this->mailbox->alerts($office, self::PREVIEW_LIMIT, true);
        foreach ($alerts->items() as $alert) {
            if (! $alert instanceof MailboxAlert) {
                continue;
            }
            $row = $alert->toPublicArray();
            $items[] = [
                'id' => 'alert-'.($row['id'] ?? uniqid()),
                'type' => 'alert',
                'severity' => $row['severity'] ?? null,
                'title' => $row['title'] ?? 'Alerta e-CAC',
                'body' => $row['body'] ?? null,
                'client_id' => $row['client_id'] ?? null,
                'occurred_at' => $row['created_at'] ?? null,
                'deep_link' => $row['deep_link'] ?? null,
            ];
        }

        usort($items, static function (array $a, array $b): int {
            return strcmp((string) ($b['occurred_at'] ?? ''), (string) ($a['occurred_at'] ?? ''));
        });

        return ['items' => array_slice($items, 0, 20)];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildDeclarationsAbsence(Office $office): array
    {
        $rows = $this->declarations->summaryByObligation($office);
        $upToDate = 0;
        $open = 0;
        $byObligation = [];

        foreach ($rows as $row) {
            if (($row['applicability'] ?? null) !== 'APPLICABLE') {
                continue;
            }
            $status = strtoupper((string) ($row['delivery_status'] ?? ''));
            $total = (int) ($row['total'] ?? 0);
            $code = (string) ($row['obligation_code'] ?? 'UNKNOWN');
            if (! isset($byObligation[$code])) {
                $byObligation[$code] = [
                    'obligation_code' => $code,
                    'obligation_name' => $row['obligation_name'] ?? $code,
                    'up_to_date' => 0,
                    'open' => 0,
                ];
            }
            if ($status === 'UP_TO_DATE') {
                $upToDate += $total;
                $byObligation[$code]['up_to_date'] += $total;
            } else {
                $open += $total;
                $byObligation[$code]['open'] += $total;
            }
        }

        return [
            'up_to_date_count' => $upToDate,
            'open_count' => $open,
            'by_obligation' => array_values($byObligation),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSitfis(Office $office): array
    {
        $overview = $this->portfolio->overview(
            $office,
            FiscalModuleKey::Sitfis,
            new ModulePortfolioFilters(submodule: 'SITFIS'),
        );
        $arr = $overview->toArray();

        return [
            'counters' => $arr['counters'],
            'total_clients' => $arr['total_clients'],
            'coverage' => $arr['coverage'],
            'data_origin' => $arr['data_origin'],
            'is_synthetic' => $arr['is_synthetic'],
            'as_of' => $arr['as_of'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildObligationsProgress(Office $office): array
    {
        $specs = [
            ['code' => 'PGDAS', 'label' => 'PGDAS', 'module' => FiscalModuleKey::Declarations, 'submodule' => 'PGDAS'],
            ['code' => 'DCTFWEB', 'label' => 'DCTFWeb', 'module' => FiscalModuleKey::Declarations, 'submodule' => 'DCTFWEB'],
            ['code' => 'FGTS', 'label' => 'FGTS Digital', 'module' => FiscalModuleKey::Declarations, 'submodule' => 'FGTS'],
            ['code' => 'DEFIS', 'label' => 'DEFIS', 'module' => FiscalModuleKey::Declarations, 'submodule' => 'DEFIS'],
            ['code' => 'DIRF', 'label' => 'DIRF', 'module' => FiscalModuleKey::Declarations, 'submodule' => 'DIRF'],
        ];

        $out = [];
        foreach ($specs as $spec) {
            $overview = $this->portfolio->overview(
                $office,
                $spec['module'],
                new ModulePortfolioFilters(submodule: $spec['submodule']),
            );
            $arr = $overview->toArray();
            $coverage = (string) ($arr['coverage'] ?? '');
            $isUnsupported = $coverage === 'UNSUPPORTED' || $spec['code'] === 'DIRF';
            $out[] = [
                'code' => $spec['code'],
                'label' => $spec['label'],
                'completed' => $isUnsupported ? null : (int) ($arr['counters']['up_to_date'] ?? 0),
                'total' => $isUnsupported ? null : (int) ($arr['total_clients'] ?? 0),
                'error' => (int) ($arr['counters']['error'] ?? 0),
                'coverage' => $isUnsupported ? 'UNSUPPORTED' : $coverage,
                'data_origin' => $arr['data_origin'],
                'is_synthetic' => (bool) ($arr['is_synthetic'] ?? false),
            ];
        }

        return $out;
    }
}
