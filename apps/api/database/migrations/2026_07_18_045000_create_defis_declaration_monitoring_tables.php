<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('defis_declaration_observations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('calendar_year');
            $table->string('declaration_type', 1);
            $table->string('digest', 64);
            $table->timestampTz('observed_at');
            $table->foreignId('source_run_id')->nullable()->constrained('fiscal_monitoring_runs')->nullOnDelete();
            $table->string('source_provenance', 32);
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['office_id', 'client_id', 'digest'], 'defis_obs_office_client_digest_uq');
            $table->index(['office_id', 'client_id', 'observed_at'], 'defis_obs_office_client_observed_idx');
        });

        Schema::create('defis_declaration_projections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('calendar_year');
            $table->string('declaration_type', 1);
            $table->timestampTz('last_observed_at');
            $table->foreignId('last_observation_id')->nullable()->constrained('defis_declaration_observations')->nullOnDelete();
            $table->foreignId('last_run_id')->nullable()->constrained('fiscal_monitoring_runs')->nullOnDelete();
            $table->string('source_provenance', 32);
            $table->timestamps();

            $table->unique(['office_id', 'client_id', 'calendar_year', 'declaration_type'], 'defis_proj_office_client_year_type_uq');
            $table->index(['office_id', 'client_id', 'last_observed_at'], 'defis_proj_office_client_observed_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('defis_declaration_projections');
        Schema::dropIfExists('defis_declaration_observations');
    }
};
