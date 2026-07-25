<?php

use App\Support\FiscalDataModel\MigrationPrecondition;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CHECK constraints para estados internos críticos + no máximo um snapshot corrente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        MigrationPrecondition::tableExists('sync_cursors', 'internal_state_checks');
        MigrationPrecondition::tableExists('outbound_recovery_cases', 'internal_state_checks');

        DB::statement(<<<'SQL'
            ALTER TABLE sync_cursors
            DROP CONSTRAINT IF EXISTS sync_cursors_status_check;
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE sync_cursors
            ADD CONSTRAINT sync_cursors_status_check
            CHECK (status IN ('IDLE','RUNNING','WAITING','ERROR','BLOCKED'));
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE outbound_recovery_cases
            DROP CONSTRAINT IF EXISTS outbound_recovery_cases_completeness_check;
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE outbound_recovery_cases
            ADD CONSTRAINT outbound_recovery_cases_completeness_check
            CHECK (completeness IN ('OPEN','SATISFIED','FAILED','CANCELLED'));
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE outbound_recovery_cases
            DROP CONSTRAINT IF EXISTS outbound_recovery_cases_urgency_check;
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE outbound_recovery_cases
            ADD CONSTRAINT outbound_recovery_cases_urgency_check
            CHECK (urgency IN ('LOW','NORMAL','HIGH','CRITICAL') AND urgency <> 'CAPTURED');
            SQL);

        // Snapshot corrente: se coluna is_current existir
        $hasIsCurrent = DB::selectOne(<<<'SQL'
            SELECT 1 AS ok FROM information_schema.columns
            WHERE table_schema='public' AND table_name='fiscal_snapshots' AND column_name='is_current'
            SQL);
        if ($hasIsCurrent !== null) {
            // Dedupe na mesma chave do índice antes do unique (evita falha e perda por competence).
            DB::statement(<<<'SQL'
                WITH ranked AS (
                    SELECT id,
                           ROW_NUMBER() OVER (
                               PARTITION BY office_id, client_id, system_code, service_code,
                                            COALESCE(competence_id, 0)
                               ORDER BY version DESC, id DESC
                           ) AS rn
                    FROM fiscal_snapshots
                    WHERE is_current = true
                )
                UPDATE fiscal_snapshots s
                SET is_current = false
                FROM ranked r
                WHERE s.id = r.id AND r.rn > 1
                SQL);

            // Identidade: office+client+system+service+competence (nullable → 0)
            DB::statement(<<<'SQL'
                CREATE UNIQUE INDEX IF NOT EXISTS fiscal_snapshots_one_current_per_identity
                ON fiscal_snapshots (
                    office_id,
                    client_id,
                    system_code,
                    service_code,
                    COALESCE(competence_id, 0)
                )
                WHERE is_current = true
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE sync_cursors DROP CONSTRAINT IF EXISTS sync_cursors_status_check');
        DB::statement('ALTER TABLE outbound_recovery_cases DROP CONSTRAINT IF EXISTS outbound_recovery_cases_completeness_check');
        DB::statement('ALTER TABLE outbound_recovery_cases DROP CONSTRAINT IF EXISTS outbound_recovery_cases_urgency_check');
        DB::statement('DROP INDEX IF EXISTS fiscal_snapshots_one_current_per_identity');
    }
};
