<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Verificação somente leitura antes da migração de cadastro ampliado.
 * Não corrige dados; emite diagnóstico sanitizado (sem payload externo, PFX ou QSA).
 */
class PreflightClientRegistrationExpandCommand extends Command
{
    protected $signature = 'clients:preflight-registration-expand
                            {--json : Emite o relatório em JSON}
                            {--fail-on-issues : Exit code 1 se houver bloqueios}';

    protected $description = 'Relatório pré-migração do cadastro ampliado (somente leitura)';

    public function handle(): int
    {
        if (! Schema::hasTable('clients') || ! Schema::hasTable('establishments')) {
            $this->error('Tabelas clients/establishments ausentes.');

            return self::FAILURE;
        }

        $hasName = Schema::hasColumn('clients', 'name');
        $hasLegalName = Schema::hasColumn('clients', 'legal_name');
        $nameColumn = $hasLegalName ? 'legal_name' : ($hasName ? 'name' : null);

        if ($nameColumn === null) {
            $this->error('Coluna de nome do cliente não encontrada.');

            return self::FAILURE;
        }

        $emptyNames = DB::table('clients')
            ->where(function ($q) use ($nameColumn): void {
                $q->whereNull($nameColumn)->orWhere($nameColumn, '');
            })
            ->select(['id', 'office_id', 'root_cnpj'])
            ->get()
            ->map(fn ($row) => [
                'client_id' => (int) $row->id,
                'office_id' => (int) $row->office_id,
                'root_cnpj' => (string) $row->root_cnpj,
            ])
            ->all();

        $duplicateRoots = DB::table('clients')
            ->select(['office_id', 'root_cnpj', DB::raw('COUNT(*) as total')])
            ->groupBy('office_id', 'root_cnpj')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->map(fn ($row) => [
                'office_id' => (int) $row->office_id,
                'root_cnpj' => (string) $row->root_cnpj,
                'count' => (int) $row->total,
            ])
            ->all();

        $duplicateCnpjs = DB::table('establishments')
            ->select(['office_id', 'cnpj', DB::raw('COUNT(*) as total')])
            ->groupBy('office_id', 'cnpj')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->map(fn ($row) => [
                'office_id' => (int) $row->office_id,
                'cnpj' => (string) $row->cnpj,
                'count' => (int) $row->total,
            ])
            ->all();

        $multipleMatrices = DB::table('establishments')
            ->where('is_matrix', true)
            ->select(['client_id', 'office_id', DB::raw('COUNT(*) as total')])
            ->groupBy('client_id', 'office_id')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->map(fn ($row) => [
                'client_id' => (int) $row->client_id,
                'office_id' => (int) $row->office_id,
                'matrix_count' => (int) $row->total,
            ])
            ->all();

        $inactiveCursors = [];
        if (Schema::hasTable('sync_cursors')) {
            $inactiveCursors = DB::table('sync_cursors as sc')
                ->join('establishments as e', 'e.id', '=', 'sc.establishment_id')
                ->join('clients as c', 'c.id', '=', 'e.client_id')
                ->where(function ($q): void {
                    $q->where('c.is_active', false)
                        ->orWhere('e.is_active', false);
                })
                ->select([
                    'sc.id as cursor_id',
                    'sc.establishment_id',
                    'sc.last_nsu',
                    'c.id as client_id',
                    'c.is_active as client_active',
                    'e.is_active as establishment_active',
                ])
                ->get()
                ->map(fn ($row) => [
                    'cursor_id' => (int) $row->cursor_id,
                    'establishment_id' => (int) $row->establishment_id,
                    'client_id' => (int) $row->client_id,
                    'last_nsu' => (int) $row->last_nsu,
                    'client_active' => (bool) $row->client_active,
                    'establishment_active' => (bool) $row->establishment_active,
                ])
                ->all();
        }

        $blockers = [
            'empty_names' => count($emptyNames),
            'duplicate_roots' => count($duplicateRoots),
            'duplicate_cnpjs' => count($duplicateCnpjs),
            'multiple_matrices' => count($multipleMatrices),
        ];
        $warnings = [
            'cursors_on_inactive_entities' => count($inactiveCursors),
        ];

        $canProceed = array_sum($blockers) === 0;

        $report = [
            'can_proceed' => $canProceed,
            'name_column' => $nameColumn,
            'blockers' => $blockers,
            'warnings' => $warnings,
            'details' => [
                'empty_names' => $emptyNames,
                'duplicate_roots' => $duplicateRoots,
                'duplicate_cnpjs' => $duplicateCnpjs,
                'multiple_matrices' => $multipleMatrices,
                'cursors_on_inactive_entities' => $inactiveCursors,
            ],
        ];

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            $this->info('Pré-migração cadastro ampliado (somente leitura)');
            $this->table(
                ['Checagem', 'Quantidade', 'Severidade'],
                [
                    ['Nomes vazios', $blockers['empty_names'], 'bloqueio'],
                    ['Raízes duplicadas (office+root)', $blockers['duplicate_roots'], 'bloqueio'],
                    ['CNPJs duplicados (office+cnpj)', $blockers['duplicate_cnpjs'], 'bloqueio'],
                    ['Múltiplas matrizes por cliente', $blockers['multiple_matrices'], 'bloqueio'],
                    ['Cursores em entidade inativa', $warnings['cursors_on_inactive_entities'], 'aviso'],
                ],
            );
            $this->line($canProceed
                ? 'Resultado: migração pode prosseguir (sem bloqueios).'
                : 'Resultado: migração NÃO deve prosseguir até corrigir os bloqueios.');
        }

        if (! $canProceed && $this->option('fail-on-issues')) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
