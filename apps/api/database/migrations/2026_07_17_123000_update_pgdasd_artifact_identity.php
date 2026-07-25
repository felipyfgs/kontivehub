<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pgdasd_artifacts', function (Blueprint $table): void {
            $table->dropUnique('pga_office_evidence_uq');
            $table->unique(
                ['office_id', 'client_id', 'kind', 'fiscal_evidence_artifact_id'],
                'pga_office_client_kind_evidence_uq'
            );
        });
    }

    public function down(): void
    {
        Schema::table('pgdasd_artifacts', function (Blueprint $table): void {
            $table->dropUnique('pga_office_client_kind_evidence_uq');
            $table->unique(
                ['office_id', 'fiscal_evidence_artifact_id'],
                'pga_office_evidence_uq'
            );
        });
    }
};
