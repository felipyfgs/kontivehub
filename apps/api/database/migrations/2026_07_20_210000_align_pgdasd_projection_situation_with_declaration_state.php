<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Alinha tax_obligation_projections.situation/delivery_status ao mapeamento
 * canônico de pgdasd_declaration_state (entrega do PA esperado).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tax_obligation_projections')
            || ! Schema::hasColumn('tax_obligation_projections', 'pgdasd_declaration_state')
            || ! Schema::hasTable('tax_obligation_definitions')) {
            return;
        }

        $definitionId = DB::table('tax_obligation_definitions')
            ->where('code', 'PGDAS_D')
            ->value('id');
        if ($definitionId === null) {
            return;
        }

        $map = [
            'CURRENT' => 'UP_TO_DATE',
            'DUE_WITHIN_DEADLINE' => 'PENDING',
            'OVERDUE_NOT_FOUND' => 'ATTENTION',
            'UNVERIFIED' => 'UNKNOWN',
        ];

        foreach ($map as $state => $situation) {
            DB::table('tax_obligation_projections')
                ->where('obligation_definition_id', $definitionId)
                ->where('pgdasd_declaration_state', $state)
                ->where(function ($q) use ($situation): void {
                    $q->where('situation', '!=', $situation)
                        ->orWhere('delivery_status', '!=', $situation)
                        ->orWhereNull('situation')
                        ->orWhereNull('delivery_status');
                })
                ->update([
                    'situation' => $situation,
                    'delivery_status' => $situation,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('tax_obligation_projections')
            || ! Schema::hasColumn('tax_obligation_projections', 'pgdasd_declaration_state')
            || ! Schema::hasTable('tax_obligation_definitions')) {
            return;
        }

        $definitionId = DB::table('tax_obligation_definitions')
            ->where('code', 'PGDAS_D')
            ->value('id');
        if ($definitionId === null) {
            return;
        }

        // Best-effort: restaura o mapeamento legado OVERDUE → PENDING.
        DB::table('tax_obligation_projections')
            ->where('obligation_definition_id', $definitionId)
            ->where('pgdasd_declaration_state', 'OVERDUE_NOT_FOUND')
            ->where('situation', 'ATTENTION')
            ->update([
                'situation' => 'PENDING',
                'delivery_status' => 'PENDING',
                'updated_at' => now(),
            ]);
    }
};
