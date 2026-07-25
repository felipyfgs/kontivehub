<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Garante no máximo um is_current=true por identidade do índice canônico
 * (office, client, system, service, COALESCE(competence_id, 0)).
 * Mantém o snapshot de maior version/id; demais perdem is_current.
 *
 * Preferir executar antes do unique em 400700; este arquivo permanece como
 * rede de segurança se 400700 já rodou sem dedupe por competence.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $hasTable = DB::selectOne(<<<'SQL'
            SELECT 1 AS ok FROM information_schema.tables
            WHERE table_schema = 'public' AND table_name = 'fiscal_snapshots'
            SQL);
        if ($hasTable === null) {
            return;
        }

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
    }

    public function down(): void
    {
        // irreversível sem histórico de is_current
    }
};
