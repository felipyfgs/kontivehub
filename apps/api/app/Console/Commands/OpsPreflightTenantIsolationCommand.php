<?php

namespace App\Console\Commands;

use App\Enums\TenantRole;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Verificação somente leitura de isolamento multi-tenant / consistência de tenant_id.
 * Não corrige dados; emite diagnóstico sanitizado (sem PFX, vault payload ou QSA).
 */
class OpsPreflightTenantIsolationCommand extends Command
{
    protected $signature = 'ops:preflight-tenant-isolation
                            {--json : Emite o relatório em JSON}
                            {--fail-on-issues : Exit code 1 se houver bloqueios}';

    protected $description = 'Preflight de isolamento multi-tenant (somente leitura)';

    public function handle(): int
    {
        if (! Schema::hasTable('tenants') || ! Schema::hasTable('tenant_memberships')) {
            $this->error('Tabelas tenants/tenant_memberships ausentes.');

            return self::FAILURE;
        }

        $membershipIssues = $this->checkMemberships();
        $nullTenantId = $this->checkNullTenantIds();
        $vaultOrphans = $this->checkVaultOrphans();
        $pendingMigrations = $this->checkPendingMigrations();
        $duplicateCritical = $this->checkCriticalDuplicates();

        $blockers = [
            'membership_orphans' => $membershipIssues['orphan_tenant'] + $membershipIssues['orphan_user'],
            'invalid_roles' => $membershipIssues['invalid_roles'],
            // Somente colunas tenant_id NOT NULL com nulos (violação de invariante).
            'null_tenant_id_rows' => $nullTenantId['total_null_required'],
            'critical_duplicates' => $duplicateCritical['total'],
            'pending_migrations' => count($pendingMigrations['pending']),
        ];

        $warnings = [
            'active_membership_on_inactive_tenant' => $membershipIssues['active_on_inactive_tenant'],
            'users_with_multiple_active_memberships' => $membershipIssues['multi_active_users'],
            'tenants_without_active_membership' => $membershipIssues['tenants_without_active'],
            'nullable_tenant_id_null_rows' => $nullTenantId['total_null_optional'],
            'vault_orphan_scan_limited' => $vaultOrphans['limited'] ? 1 : 0,
            'vault_null_refs' => $vaultOrphans['null_ref_total'],
        ];

        $canProceed = array_sum($blockers) === 0;

        $report = [
            'can_proceed' => $canProceed,
            'blockers' => $blockers,
            'warnings' => $warnings,
            'details' => [
                'memberships' => $membershipIssues,
                'null_tenant_id' => $nullTenantId,
                'vault' => $vaultOrphans,
                'pending_migrations' => $pendingMigrations,
                'critical_duplicates' => $duplicateCritical,
            ],
        ];

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            $this->info('Preflight isolamento multi-tenant (somente leitura)');
            $this->table(
                ['Checagem', 'Quantidade', 'Severidade'],
                [
                    ['Memberships órfãs (tenant/user)', $blockers['membership_orphans'], 'bloqueio'],
                    ['Roles inválidos em tenant_memberships', $blockers['invalid_roles'], 'bloqueio'],
                    ['tenant_id nulo em colunas obrigatórias', $blockers['null_tenant_id_rows'], 'bloqueio'],
                    ['Duplicidades críticas', $blockers['critical_duplicates'], 'bloqueio'],
                    ['Migrations pendentes', $blockers['pending_migrations'], 'bloqueio'],
                    ['Membership ativa em tenant inativo', $warnings['active_membership_on_inactive_tenant'], 'aviso'],
                    ['Usuários com múltiplas memberships ativas', $warnings['users_with_multiple_active_memberships'], 'aviso'],
                    ['Tenants sem membership ativa', $warnings['tenants_without_active_membership'], 'aviso'],
                    ['tenant_id nulo em colunas nullable (ex.: audit)', $warnings['nullable_tenant_id_null_rows'], 'aviso'],
                    ['Scan de vault limitado (sem catálogo)', $warnings['vault_orphan_scan_limited'], 'aviso'],
                    ['Referências vault nulas em colunas esperadas', $warnings['vault_null_refs'], 'aviso'],
                ],
            );
            $this->line($canProceed
                ? 'Resultado: preflight OK (sem bloqueios).'
                : 'Resultado: preflight com bloqueios — revisar details/JSON.');
        }

        if (! $canProceed && $this->option('fail-on-issues')) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return array{
     *     orphan_tenant: int,
     *     orphan_user: int,
     *     invalid_roles: int,
     *     active_on_inactive_tenant: int,
     *     multi_active_users: int,
     *     tenants_without_active: int,
     *     samples: array<string, list<array<string, mixed>>>
     * }
     */
    private function checkMemberships(): array
    {
        $validRoles = array_map(fn (TenantRole $r) => $r->value, TenantRole::cases());

        $orphanTenant = (int) DB::table('tenant_memberships as ou')
            ->leftJoin('tenants as o', 'o.id', '=', 'ou.tenant_id')
            ->whereNull('o.id')
            ->count();

        $orphanUser = (int) DB::table('tenant_memberships as ou')
            ->leftJoin('users as u', 'u.id', '=', 'ou.user_id')
            ->whereNull('u.id')
            ->count();

        $invalidRoles = (int) DB::table('tenant_memberships')
            ->whereNotIn('role', $validRoles)
            ->count();

        $activeOnInactive = (int) DB::table('tenant_memberships as ou')
            ->join('tenants as o', 'o.id', '=', 'ou.tenant_id')
            ->where('ou.is_active', true)
            ->where('o.is_active', false)
            ->count();

        $multiActiveUsers = (int) DB::table('tenant_memberships')
            ->where('is_active', true)
            ->select('user_id')
            ->groupBy('user_id')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count();

        $tenantsWithoutActive = (int) DB::table('tenants as o')
            ->leftJoin('tenant_memberships as ou', function ($join): void {
                $join->on('ou.tenant_id', '=', 'o.id')
                    ->where('ou.is_active', '=', true);
            })
            ->where('o.is_active', true)
            ->whereNull('ou.id')
            ->count();

        $samples = [
            'orphan_tenant' => DB::table('tenant_memberships as ou')
                ->leftJoin('tenants as o', 'o.id', '=', 'ou.tenant_id')
                ->whereNull('o.id')
                ->limit(20)
                ->get(['ou.id', 'ou.tenant_id', 'ou.user_id'])
                ->map(fn ($r) => [
                    'membership_id' => (int) $r->id,
                    'tenant_id' => (int) $r->tenant_id,
                    'user_id' => (int) $r->user_id,
                ])
                ->all(),
            'invalid_roles' => DB::table('tenant_memberships')
                ->whereNotIn('role', $validRoles)
                ->limit(20)
                ->get(['id', 'tenant_id', 'user_id', 'role'])
                ->map(fn ($r) => [
                    'membership_id' => (int) $r->id,
                    'tenant_id' => (int) $r->tenant_id,
                    'user_id' => (int) $r->user_id,
                    'role' => (string) $r->role,
                ])
                ->all(),
        ];

        return [
            'orphan_tenant' => $orphanTenant,
            'orphan_user' => $orphanUser,
            'invalid_roles' => $invalidRoles,
            'active_on_inactive_tenant' => $activeOnInactive,
            'multi_active_users' => $multiActiveUsers,
            'tenants_without_active' => $tenantsWithoutActive,
            'samples' => $samples,
        ];
    }

    /**
     * Heurística: tabelas com coluna tenant_id e contagem de nulos.
     * NOT NULL com nulos → bloqueio; nullable com nulos → aviso (escopo global legítimo).
     *
     * @return array{
     *     total_null_required: int,
     *     total_null_optional: int,
     *     required_tables: list<array{table: string, null_count: int}>,
     *     optional_tables: list<array{table: string, null_count: int}>,
     *     scanned_tables: int
     * }
     */
    private function checkNullTenantIds(): array
    {
        $columns = $this->tenantIdColumns();
        $requiredHits = [];
        $optionalHits = [];
        $totalRequired = 0;
        $totalOptional = 0;

        foreach ($columns as $meta) {
            $table = $meta['table'];
            $nullable = $meta['nullable'];

            try {
                $count = (int) DB::table($table)->whereNull('tenant_id')->count();
            } catch (\Throwable) {
                continue;
            }

            if ($count === 0) {
                continue;
            }

            $entry = ['table' => $table, 'null_count' => $count];
            if ($nullable) {
                $optionalHits[] = $entry;
                $totalOptional += $count;
            } else {
                $requiredHits[] = $entry;
                $totalRequired += $count;
            }
        }

        return [
            'total_null_required' => $totalRequired,
            'total_null_optional' => $totalOptional,
            'required_tables' => $requiredHits,
            'optional_tables' => $optionalHits,
            'scanned_tables' => count($columns),
        ];
    }

    /**
     * @return list<array{table: string, nullable: bool}>
     */
    private function tenantIdColumns(): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT table_name, is_nullable
            FROM information_schema.columns
            WHERE table_schema = current_schema()
              AND column_name = 'tenant_id'
              AND table_name NOT LIKE 'pg_%'
            ORDER BY table_name
        SQL);

        return array_values(array_map(fn ($row) => [
            'table' => (string) $row->table_name,
            'nullable' => strtoupper((string) $row->is_nullable) === 'YES',
        ], $rows));
    }

    /**
     * Vault: não há catálogo central de objetos; reporta limitação e refs nulas em colunas conhecidas.
     *
     * @return array{
     *     limited: bool,
     *     limitation: string,
     *     null_ref_total: int,
     *     columns: list<array{table: string, column: string, null_count: int}>
     * }
     */
    private function checkVaultOrphans(): array
    {
        $known = [
            ['client_credentials', 'vault_object_id'],
            ['dfe_documents', 'vault_object_id'],
            ['tenant_credentials', 'vault_object_id'],
            ['fiscal_document_quarantines', 'vault_object_id'],
            ['document_import_batches', 'spool_vault_object_id'],
            ['document_import_batch_items', 'spool_vault_object_id'],
            ['client_custom_fields', 'vault_object_id'],
            ['outbound_capture_profiles', 'csc_vault_object_id'],
            ['outbound_series_cursors', 'seed_vault_object_id'],
            ['outbound_monthly_readiness', 'manifest_vault_object_id'],
        ];

        // Descobre colunas *vault* no schema real (heurística)
        $discovered = $this->discoverVaultRefColumns();
        $candidates = [];
        foreach (array_merge($known, $discovered) as $pair) {
            $key = $pair[0].'.'.$pair[1];
            $candidates[$key] = $pair;
        }

        $nullRefs = [];
        $nullTotal = 0;

        foreach ($candidates as [$table, $column]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            try {
                $count = (int) DB::table($table)->whereNull($column)->count();
            } catch (\Throwable) {
                continue;
            }

            // Colunas nullable (ex.: spool) podem ter nulos legítimos — só alerta se houver linhas e coluna tipicamente obrigatória.
            // Reportamos contagem; severidade fica em warning.
            if ($count > 0) {
                $nullRefs[] = [
                    'table' => $table,
                    'column' => $column,
                    'null_count' => $count,
                ];
                $nullTotal += $count;
            }
        }

        return [
            'limited' => true,
            'limitation' => 'Não há tabela de metadados central do cofre (SecureObjectStore é filesystem). '
                .'Impossível enumerar objetos órfãos no disco vs DB sem inventário dedicado. '
                .'Somente referências nulas em colunas *vault* foram contadas.',
            'null_ref_total' => $nullTotal,
            'columns' => $nullRefs,
            'scanned_candidates' => count($candidates),
        ];
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function discoverVaultRefColumns(): array
    {
        $pairs = [];

        $rows = DB::select(<<<'SQL'
            SELECT table_name, column_name
            FROM information_schema.columns
            WHERE table_schema = current_schema()
              AND (
                column_name LIKE '%vault_object_id%'
                OR column_name LIKE '%vault%object%'
              )
            ORDER BY table_name, column_name
        SQL);
        foreach ($rows as $row) {
            $pairs[] = [(string) $row->table_name, (string) $row->column_name];
        }

        return $pairs;
    }

    /**
     * Usa o Migrator (sem Artisan::call) para não poluir stdout do relatório.
     *
     * @return array{pending: list<string>, ran: int, status_available: bool}
     */
    private function checkPendingMigrations(): array
    {
        try {
            $migrator = app('migrator');
            if (! $migrator->repositoryExists()) {
                return [
                    'pending' => ['repositório de migrations ausente'],
                    'ran' => 0,
                    'status_available' => true,
                ];
            }

            /** @var array<string, string> $files name => path */
            $files = $migrator->getMigrationFiles([database_path('migrations')]);
            $ran = $migrator->getRepository()->getRan();
            $pending = array_values(array_diff(array_keys($files), $ran));

            return [
                'pending' => $pending,
                'ran' => count($ran),
                'status_available' => true,
            ];
        } catch (\Throwable $e) {
            return [
                'pending' => ['checagem de migrations indisponível: '.$e->getMessage()],
                'ran' => 0,
                'status_available' => false,
            ];
        }
    }

    /**
     * Duplicidades que quebram isolamento / unicidade de negócio conhecida.
     *
     * @return array{total: int, items: list<array<string, mixed>>}
     */
    private function checkCriticalDuplicates(): array
    {
        $items = [];

        // tenant_memberships: unique (tenant_id, user_id) — se constraint ausente em algum env, detecta
        $dupMemberships = DB::table('tenant_memberships')
            ->select(['tenant_id', 'user_id', DB::raw('COUNT(*) as total')])
            ->groupBy('tenant_id', 'user_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($dupMemberships as $row) {
            $items[] = [
                'kind' => 'tenant_memberships_pair',
                'tenant_id' => (int) $row->tenant_id,
                'user_id' => (int) $row->user_id,
                'count' => (int) $row->total,
            ];
        }

        if (Schema::hasTable('tenants') && Schema::hasColumn('tenants', 'slug')) {
            $dupSlugs = DB::table('tenants')
                ->select(['slug', DB::raw('COUNT(*) as total')])
                ->groupBy('slug')
                ->havingRaw('COUNT(*) > 1')
                ->get();
            foreach ($dupSlugs as $row) {
                $items[] = [
                    'kind' => 'tenant_slug',
                    'slug' => (string) $row->slug,
                    'count' => (int) $row->total,
                ];
            }
        }

        return [
            'total' => count($items),
            'items' => $items,
        ];
    }
}
