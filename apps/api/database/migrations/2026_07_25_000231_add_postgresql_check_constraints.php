<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE clients
            ADD CONSTRAINT clients_root_cnpj_format_check
            CHECK (root_cnpj ~ '^[0-9A-Z]{8}$')
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE clients
            ADD CONSTRAINT clients_tax_regime_check
            CHECK (
                tax_regime IS NULL
                OR tax_regime IN (
                    'SIMPLES_NACIONAL',
                    'MEI',
                    'LUCRO_PRESUMIDO',
                    'LUCRO_REAL',
                    'IMUNE_ISENTO',
                    'OUTRO',
                    'UNKNOWN'
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE establishments
            ADD CONSTRAINT establishments_cnpj_format_check
            CHECK (cnpj ~ '^[0-9A-Z]{14}$')
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE tenant_memberships
            ADD CONSTRAINT tenant_memberships_profile_check
            CHECK (
                (role = 'tenant_admin' AND permission_profile_id IS NULL)
                OR (role = 'tenant_user' AND permission_profile_id IS NOT NULL)
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tenant_memberships DROP CONSTRAINT tenant_memberships_profile_check');
        DB::statement('ALTER TABLE establishments DROP CONSTRAINT establishments_cnpj_format_check');
        DB::statement('ALTER TABLE clients DROP CONSTRAINT clients_tax_regime_check');
        DB::statement('ALTER TABLE clients DROP CONSTRAINT clients_root_cnpj_format_check');
    }
};
