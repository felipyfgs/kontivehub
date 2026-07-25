<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ccmei_registration_status_observations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('status', 64);
            $table->boolean('enquadrado_mei');
            $table->string('situation', 32);
            $table->unsignedSmallInteger('count');
            $table->char('digest', 64);
            $table->timestampTz('observed_at');
            $table->foreignId('source_run_id')->nullable()->constrained('fiscal_monitoring_runs')->nullOnDelete();
            $table->string('source_provenance', 32);
            $table->timestampTz('created_at');
            $table->unique(['office_id', 'client_id', 'digest'], 'ccmei_status_observation_digest_uq');
        });

        Schema::create('ccmei_registration_status_projections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('status', 64);
            $table->boolean('enquadrado_mei');
            $table->string('situation', 32);
            $table->unsignedSmallInteger('count');
            $table->timestampTz('last_valid_query_at');
            $table->foreignId('last_observation_id')->nullable()
                ->constrained('ccmei_registration_status_observations')->nullOnDelete();
            $table->foreignId('last_run_id')->nullable()->constrained('fiscal_monitoring_runs')->nullOnDelete();
            $table->string('source_provenance', 32);
            $table->timestamps();
            $table->unique(['office_id', 'client_id'], 'ccmei_status_projection_office_client_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ccmei_registration_status_projections');
        Schema::dropIfExists('ccmei_registration_status_observations');
    }
};
