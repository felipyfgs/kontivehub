<?php

namespace App\Console\Commands;

use App\Enums\OfficeRole;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Verificação somente leitura de isolamento multi-tenant / consistência de office_id.
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
        if (! Schema::hasTable('offices') || ! Schema::hasTable('office_user')) {
            $this->error('Tabelas offices/office_user ausentes.');

            return self::FAILURE;
        }

        $membershipIssues = $this->checkMemberships();
        $nullOfficeId = $this->checkNullOfficeIds();
        $vaultOrphans = $this->checkVaultOrphans();
        $pendingMigrations = $this->checkPendingMigrations();
        $duplicateCritical = $this->checkCriticalDuplicates();

        $blockers = [
            'membership_orphans' => $membershipIssues['orphan_office'] + $membershipIssues['orphan_user'],
            'invalid_roles' => $membershipIssues['invalid_roles'],
            // Somente colunas office_id NOT NULL com nulos (violação de invariante).
            'null_office_id_rows' => $nullOfficeId['total_null_required'],
            'critical_duplicates' => $duplicateCritical['total'],
            'pending_migrations' => count($pendingMigrations['pending']),
        ];

        $warnings = [
            'active_membership_on_inactive_office' => $membershipIssues['active_on_inactive_office'],
            'users_with_multiple_active_memberships' => $membershipIssues['multi_active_users'],
            'offices_without_active_membership' => $membershipIssues['offices_without_active'],
            'nullable_office_id_null_rows' => $nullOfficeId['total_null_optional'],
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
                'null_office_id' => $nullOfficeId,
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
                    ['Memberships órfãs (office/user)', $blockers['membership_orphans'], 'bloqueio'],
                    ['Roles inválidos em office_user', $blockers['invalid_roles'], 'bloqueio'],
                    ['office_id nulo em colunas obrigatórias', $blockers['null_office_id_rows'], 'bloqueio'],
                    ['Duplicidades críticas', $blockers['critical_duplicates'], 'bloqueio'],
                    ['Migrations pendentes', $blockers['pending_migrations'], 'bloqueio'],
                    ['Membership ativa em office inativo', $warnings['active_membership_on_inactive_office'], 'aviso'],
                    ['Usuários com múltiplas memberships ativas', $warnings['users_with_multiple_active_memberships'], 'aviso'],
                    ['Offices sem membership ativa', $warnings['offices_without_active_membership'], 'aviso'],
                    ['office_id nulo em colunas nullable (ex.: audit)', $warnings['nullable_office_id_null_rows'], 'aviso'],
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
     *     orphan_office: int,
     *     orphan_user: int,
     *     invalid_roles: int,
     *     active_on_inactive_office: int,
     *     multi_active_users: int,
     *     offices_without_active: int,
     *     samples: array<string, list<array<string, mixed>>>
     * }
     */
    private function checkMemberships(): array
    {
        $validRoles = array_map(fn (OfficeRole $r) => $r->value, OfficeRole::cases());

        $orphanOffice = (int) DB::table('office_user as ou')
            ->leftJoin('offices as o', 'o.id', '=', 'ou.office_id')
            ->whereNull('o.id')
            ->count();

        $orphanUser = (int) DB::table('office_user as ou')
            ->leftJoin('users as u', 'u.id', '=', 'ou.user_id')
            ->whereNull('u.id')
            ->count();

        $invalidRoles = (int) DB::table('office_user')
            ->whereNotIn('role', $validRoles)
            ->count();

        $activeOnInactive = (int) DB::table('office_user as ou')
            ->join('offices as o', 'o.id', '=', 'ou.office_id')
            ->where('ou.is_active', true)
            ->where('o.is_active', false)
            ->count();

        $multiActiveUsers = (int) DB::table('office_user')
            ->where('is_active', true)
            ->select('user_id')
            ->groupBy('user_id')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count();

        $officesWithoutActive = (int) DB::table('offices as o')
            ->leftJoin('office_user as ou', function ($join): void {
                $join->on('ou.office_id', '=', 'o.id')
                    ->where('ou.is_active', '=', true);
            })
            ->where('o.is_active', true)
            ->whereNull('ou.id')
            ->count();

        $samples = [
            'orphan_office' => DB::table('office_user as ou')
                ->leftJoin('offices as o', 'o.id', '=', 'ou.office_id')
                ->whereNull('o.id')
                ->limit(20)
                ->get(['ou.id', 'ou.office_id', 'ou.user_id'])
                ->map(fn ($r) => [
                    'membership_id' => (int) $r->id,
                    'office_id' => (int) $r->office_id,
                    'user_id' => (int) $r->user_id,
                ])
                ->all(),
            'invalid_roles' => DB::table('office_user')
                ->whereNotIn('role', $validRoles)
                ->limit(20)
                ->get(['id', 'office_id', 'user_id', 'role'])
                ->map(fn ($r) => [
                    'membership_id' => (int) $r->id,
                    'office_id' => (int) $r->office_id,
                    'user_id' => (int) $r->user_id,
                    'role' => (string) $r->role,
                ])
                ->all(),
        ];

        return [
            'orphan_office' => $orphanOffice,
            'orphan_user' => $orphanUser,
            'invalid_roles' => $invalidRoles,
            'active_on_inactive_office' => $activeOnInactive,
            'multi_active_users' => $multiActiveUsers,
            'offices_without_active' => $officesWithoutActive,
            'samples' => $samples,
        ];
    }

    /**
     * Heurística: tabelas com coluna office_id e contagem de nulos.
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
    private function checkNullOfficeIds(): array
    {
        $columns = $this->officeIdColumns();
        $requiredHits = [];
        $optionalHits = [];
        $totalRequired = 0;
        $totalOptional = 0;

        foreach ($columns as $meta) {
            $table = $meta['table'];
            $nullable = $meta['nullable'];

            try {
                $count = (int) DB::table($table)->whereNull('office_id')->count();
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
    private function officeIdColumns(): array
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            $rows = DB::select(<<<'SQL'
                SELECT table_name, is_nullable
                FROM information_schema.columns
                WHERE table_schema = current_schema()
                  AND column_name = 'office_id'
                  AND table_name NOT LIKE 'pg_%'
                ORDER BY table_name
            SQL);

            return array_values(array_map(fn ($r) => [
                'table' => (string) $r->table_name,
                'nullable' => strtoupper((string) $r->is_nullable) === 'YES',
            ], $rows));
        }

        if ($driver === 'sqlite') {
            $names = array_map(
                fn ($r) => (string) $r->name,
                DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"),
            );
            $out = [];
            foreach ($names as $name) {
                if (! Schema::hasColumn($name, 'office_id')) {
                    continue;
                }
                $nullable = true;
                try {
                    $cols = DB::select('PRAGMA table_info('.$this->quoteSqliteIdent($name).')');
                    foreach ($cols as $col) {
                        if ((string) ($col->name ?? '') === 'office_id') {
                            // notnull=1 significa NOT NULL
                            $nullable = ((int) ($col->notnull ?? 0)) === 0;
                            break;
                        }
                    }
                } catch (\Throwable) {
                    // assume nullable se não der para inspecionar
                }
                $out[] = ['table' => $name, 'nullable' => $nullable];
            }

            return $out;
        }

        $out = [];
        foreach (Schema::getTableListing() as $name) {
            if (Schema::hasColumn($name, 'office_id')) {
                $out[] = ['table' => $name, 'nullable' => true];
            }
        }

        return $out;
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
            ['office_credentials', 'vault_object_id'],
            ['fiscal_document_quarantine', 'vault_object_id'],
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
        $driver = Schema::getConnection()->getDriverName();
        $pairs = [];

        if ($driver === 'pgsql') {
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
            foreach ($rows as $r) {
                $pairs[] = [(string) $r->table_name, (string) $r->column_name];
            }

            return $pairs;
        }

        if ($driver === 'sqlite') {
            $names = array_map(
                fn ($r) => (string) $r->name,
                DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"),
            );
            foreach ($names as $name) {
                try {
                    $cols = DB::select('PRAGMA table_info('.$this->quoteSqliteIdent($name).')');
                } catch (\Throwable) {
                    continue;
                }
                foreach ($cols as $col) {
                    $colName = (string) ($col->name ?? '');
                    if ($colName !== '' && str_contains($colName, 'vault')) {
                        $pairs[] = [$name, $colName];
                    }
                }
            }

            return $pairs;
        }

        return [];
    }

    private function quoteSqliteIdent(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
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

        // office_user: unique (office_id, user_id) — se constraint ausente em algum env, detecta
        $dupMemberships = DB::table('office_user')
            ->select(['office_id', 'user_id', DB::raw('COUNT(*) as total')])
            ->groupBy('office_id', 'user_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($dupMemberships as $row) {
            $items[] = [
                'kind' => 'office_user_pair',
                'office_id' => (int) $row->office_id,
                'user_id' => (int) $row->user_id,
                'count' => (int) $row->total,
            ];
        }

        if (Schema::hasTable('offices') && Schema::hasColumn('offices', 'slug')) {
            $dupSlugs = DB::table('offices')
                ->select(['slug', DB::raw('COUNT(*) as total')])
                ->groupBy('slug')
                ->havingRaw('COUNT(*) > 1')
                ->get();
            foreach ($dupSlugs as $row) {
                $items[] = [
                    'kind' => 'office_slug',
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
