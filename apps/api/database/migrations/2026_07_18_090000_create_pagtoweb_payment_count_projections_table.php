<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pagtoweb_payment_count_observations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('payment_count');
            $table->json('filter_summary');
            $table->char('digest', 64);
            $table->timestampTz('observed_at');
            $table->foreignId('source_run_id')->nullable()->constrained('fiscal_monitoring_runs')->nullOnDelete();
            $table->string('source_provenance', 32);
            $table->timestampTz('created_at');
            $table->unique(['office_id', 'client_id', 'digest'], 'pagtoweb_payment_count_observation_uq');
        });

        Schema::create('pagtoweb_payment_count_projections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('payment_count');
            $table->json('filter_summary');
            $table->timestampTz('last_valid_query_at');
            $table->foreignId('last_observation_id')->nullable()->constrained('pagtoweb_payment_count_observations')->nullOnDelete();
            $table->foreignId('last_run_id')->nullable()->constrained('fiscal_monitoring_runs')->nullOnDelete();
            $table->string('source_provenance', 32);
            $table->timestamps();
            $table->unique(['office_id', 'client_id'], 'pagtoweb_payment_count_projection_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pagtoweb_payment_count_projections');
        Schema::dropIfExists('pagtoweb_payment_count_observations');
    }
};
