<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('defis_declaration_references', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('vault_object_id', 26);
            $table->timestampTz('observed_at');
            $table->foreignId('source_run_id')->nullable()->constrained('fiscal_monitoring_runs')->nullOnDelete();
            $table->string('source_provenance', 32);
            $table->timestamps();
            $table->index(['office_id', 'client_id', 'observed_at'], 'defis_ref_office_client_observed_idx');
        });
        Schema::table('defis_declaration_projections', function (Blueprint $table): void {
            $table->foreignId('defis_declaration_reference_id')->nullable()->after('last_run_id')
                ->constrained('defis_declaration_references')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('defis_declaration_projections', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('defis_declaration_reference_id');
        });
        Schema::dropIfExists('defis_declaration_references');
    }
};
