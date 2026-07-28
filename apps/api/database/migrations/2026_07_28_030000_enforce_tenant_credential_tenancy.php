<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = true;

    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE tenant_credentials
            DROP CONSTRAINT IF EXISTS tenant_credentials_fingerprint_status_unique
            SQL);

        Schema::table('tenant_credentials', function (Blueprint $table): void {
            $table->unique(
                ['tenant_id', 'id'],
                'tenant_credentials_tenant_id_id_unique',
            );
        });

        Schema::table('tenant_credential_purpose_links', function (Blueprint $table): void {
            $table->dropForeign('tenant_credential_purpose_links_tenant_credential_id_foreign');
            $table->foreign(
                ['tenant_id', 'tenant_credential_id'],
                'tcpl_tenant_credential_tenant_fk',
            )
                ->references(['tenant_id', 'id'])
                ->on('tenant_credentials')
                ->onUpdate('no action')
                ->onDelete('cascade');
        });

        Schema::table('fgts_digital_representations', function (Blueprint $table): void {
            $table->dropForeign('fgts_digital_representations_tenant_credential_id_foreign');
            $table->index(
                ['tenant_id', 'tenant_credential_id'],
                'fgts_rep_tenant_credential_idx',
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE fgts_digital_representations
            ADD CONSTRAINT fgts_rep_tenant_credential_tenant_fk
            FOREIGN KEY (tenant_id, tenant_credential_id)
            REFERENCES tenant_credentials (tenant_id, id)
            ON UPDATE NO ACTION
            ON DELETE SET NULL (tenant_credential_id)
            SQL);
    }

    public function down(): void
    {
        $hasDuplicateHistoricalRows = DB::table('tenant_credentials')
            ->select(['tenant_id', 'fingerprint_sha256', 'status'])
            ->groupBy('tenant_id', 'fingerprint_sha256', 'status')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasDuplicateHistoricalRows) {
            throw new RuntimeException(
                'Rollback bloqueado: tenant_credentials contém históricos repetidos incompatíveis com a constraint legada.',
            );
        }

        Schema::table('tenant_credential_purpose_links', function (Blueprint $table): void {
            $table->dropForeign('tcpl_tenant_credential_tenant_fk');
            $table->foreign('tenant_credential_id')
                ->references('id')
                ->on('tenant_credentials')
                ->onUpdate('no action')
                ->onDelete('cascade');
        });

        Schema::table('fgts_digital_representations', function (Blueprint $table): void {
            $table->dropForeign('fgts_rep_tenant_credential_tenant_fk');
            $table->dropIndex('fgts_rep_tenant_credential_idx');
            $table->foreign('tenant_credential_id')
                ->references('id')
                ->on('tenant_credentials')
                ->onUpdate('no action')
                ->onDelete('set null');
        });

        Schema::table('tenant_credentials', function (Blueprint $table): void {
            $table->dropUnique('tenant_credentials_tenant_id_id_unique');
            $table->unique(
                ['tenant_id', 'fingerprint_sha256', 'status'],
                'tenant_credentials_fingerprint_status_unique',
            );
        });
    }
};
