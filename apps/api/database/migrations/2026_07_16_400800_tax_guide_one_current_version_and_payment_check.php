<?php

use App\Support\FiscalDataModel\MigrationPrecondition;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * No máximo uma versão corrente por guia; CHECK de payment_status normalizado.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        MigrationPrecondition::tablesExist(
            ['tax_guides', 'tax_guide_versions'],
            'tax_guide_current_version',
        );

        // Resolve múltiplas is_current=true legadas: mantém a de maior version_number.
        DB::statement(<<<'SQL'
            WITH ranked AS (
                SELECT id,
                       ROW_NUMBER() OVER (
                           PARTITION BY tax_guide_id
                           ORDER BY version_number DESC, id DESC
                       ) AS rn
                FROM tax_guide_versions
                WHERE is_current = true
            )
            UPDATE tax_guide_versions v
            SET is_current = false, updated_at = NOW()
            FROM ranked r
            WHERE v.id = r.id AND r.rn > 1
            SQL);

        // Alinha current_version_id da guia com a versão corrente (se existir).
        DB::statement(<<<'SQL'
            UPDATE tax_guides g
            SET current_version_id = v.id, updated_at = NOW()
            FROM tax_guide_versions v
            WHERE v.tax_guide_id = g.id AND v.is_current = true
              AND (g.current_version_id IS DISTINCT FROM v.id)
            SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS tax_guide_versions_one_current
            ON tax_guide_versions (tax_guide_id)
            WHERE is_current = true
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE tax_guides
            DROP CONSTRAINT IF EXISTS tax_guides_payment_status_check
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE tax_guides
            ADD CONSTRAINT tax_guides_payment_status_check
            CHECK (payment_status IN (
                'UNKNOWN','PENDING','PAID','OVERDUE','CANCELLED','PARTIAL','NOT_APPLICABLE',
                'CONFIRMED','NOT_CONFIRMED'
            ))
            SQL);

        // Evidência de pagamento: raw + normalizado
        if (! DB::selectOne(<<<'SQL'
            SELECT 1 AS ok FROM information_schema.columns
            WHERE table_name='tax_guide_payment_confirmations' AND column_name='payment_status_normalized'
            SQL)) {
            DB::statement(<<<'SQL'
                ALTER TABLE tax_guide_payment_confirmations
                ADD COLUMN IF NOT EXISTS payment_status_normalized varchar(30) NULL,
                ADD COLUMN IF NOT EXISTS official_raw_code varchar(80) NULL
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS tax_guide_versions_one_current');
        DB::statement('ALTER TABLE tax_guides DROP CONSTRAINT IF EXISTS tax_guides_payment_status_check');
    }
};
