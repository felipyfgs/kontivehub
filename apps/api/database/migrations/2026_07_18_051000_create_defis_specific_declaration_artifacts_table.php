<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('defis_specific_declaration_artifacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('defis_declaration_reference_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->foreignId('fiscal_evidence_artifact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_run_id')->nullable()->constrained('fiscal_monitoring_runs')->nullOnDelete();
            $table->string('source_provenance', 32);
            $table->timestampTz('observed_at');
            $table->string('filename', 160);
            $table->string('content_type', 100);
            $table->string('digest', 64);
            $table->timestamps();

            $table->unique(['office_id', 'client_id', 'defis_declaration_reference_id', 'kind', 'digest'], 'defis_144_artifact_digest_uq');
            $table->index(['office_id', 'client_id', 'defis_declaration_reference_id'], 'defis_144_artifact_history_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('defis_specific_declaration_artifacts');
    }
};
