<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ccmei_certificate_observations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('status', 64);
            $table->string('situation', 32);
            $table->string('digest', 64);
            $table->timestampTz('observed_at');
            $table->foreignId('source_run_id')
                ->nullable()
                ->constrained('fiscal_monitoring_runs')
                ->nullOnDelete();
            $table->string('source_provenance', 32);
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['office_id', 'client_id', 'digest'], 'ccmei_obs_office_client_digest_uq');
            $table->index(['office_id', 'client_id', 'observed_at'], 'ccmei_obs_office_client_observed_idx');
        });

        Schema::create('ccmei_certificate_projections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('status', 64);
            $table->string('situation', 32);
            $table->timestampTz('last_valid_query_at')->nullable();
            $table->foreignId('last_observation_id')
                ->nullable()
                ->constrained('ccmei_certificate_observations')
                ->nullOnDelete();
            $table->foreignId('last_run_id')
                ->nullable()
                ->constrained('fiscal_monitoring_runs')
                ->nullOnDelete();
            $table->string('source_provenance', 32);
            $table->timestamps();

            $table->unique(['office_id', 'client_id'], 'ccmei_proj_office_client_uq');
            $table->index(['office_id', 'situation', 'last_valid_query_at'], 'ccmei_proj_office_situation_query_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ccmei_certificate_projections');
        Schema::dropIfExists('ccmei_certificate_observations');
    }
};
