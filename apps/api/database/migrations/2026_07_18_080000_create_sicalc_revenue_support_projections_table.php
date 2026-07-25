<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sicalc_revenue_support_observations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('revenue_code', 16);
            $table->string('description', 255);
            $table->json('extensions');
            $table->unsignedSmallInteger('extension_count');
            $table->char('digest', 64);
            $table->timestampTz('observed_at');
            $table->foreignId('source_run_id')->nullable()->constrained('fiscal_monitoring_runs')->nullOnDelete();
            $table->string('source_provenance', 32);
            $table->timestampTz('created_at');
            $table->unique(['office_id', 'client_id', 'revenue_code', 'digest'], 'sicalc_revenue_support_observation_uq');
        });

        Schema::create('sicalc_revenue_support_projections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('revenue_code', 16);
            $table->string('description', 255);
            $table->json('extensions');
            $table->unsignedSmallInteger('extension_count');
            $table->timestampTz('last_valid_query_at');
            $table->foreignId('last_observation_id')->nullable()
                ->constrained('sicalc_revenue_support_observations')->nullOnDelete();
            $table->foreignId('last_run_id')->nullable()->constrained('fiscal_monitoring_runs')->nullOnDelete();
            $table->string('source_provenance', 32);
            $table->timestamps();
            $table->unique(['office_id', 'client_id', 'revenue_code'], 'sicalc_revenue_support_projection_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sicalc_revenue_support_projections');
        Schema::dropIfExists('sicalc_revenue_support_observations');
    }
};
