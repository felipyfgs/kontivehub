<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Remove somente legado declarado SIMULATED. UNVERIFIED permanece em
 * quarentena porque não prova, por si só, origem sintética.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fiscal_snapshots')) {
            DB::table('fiscal_snapshots')
                ->where('source_provenance', 'SIMULATED')
                ->orWhereIn('run_id', function ($query): void {
                    if (! Schema::hasTable('fiscal_monitoring_runs')) {
                        $query->selectRaw('NULL');

                        return;
                    }

                    $query->select('id')
                        ->from('fiscal_monitoring_runs')
                        ->where('source_provenance', 'SIMULATED');
                })
                ->delete();
        }

        if (Schema::hasTable('fiscal_monitoring_runs')) {
            DB::table('fiscal_monitoring_runs')
                ->where('source_provenance', 'SIMULATED')
                ->delete();
        }

        if (Schema::hasTable('serpro_operation_attempts')) {
            DB::table('serpro_operation_attempts')
                ->where('source_provenance', 'SIMULATED')
                ->orWhere('simulated', true)
                ->delete();
        }
    }

    public function down(): void
    {
        // A remoção é irreversível: registros simulados não são evidência fiscal.
    }
};
