<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pagtoweb_payment_list_observations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->json('filter_summary');
            $table->unsignedInteger('returned_count');
            $table->char('digest', 64);
            $table->timestampTz('observed_at');
            $table->foreignId('source_run_id')->nullable()->constrained('fiscal_monitoring_runs')->nullOnDelete();
            $table->string('source_provenance', 32);
            $table->timestampTz('created_at');
            $table->unique(['office_id', 'client_id', 'digest'], 'pagtoweb_payment_list_observation_uq');
        });

        Schema::create('pagtoweb_payment_list_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('observation_id')->constrained('pagtoweb_payment_list_observations')->cascadeOnDelete();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->char('document_digest', 64);
            $table->string('document_masked', 32);
            $table->string('document_type', 80)->nullable();
            $table->string('revenue_code', 8)->nullable();
            $table->string('revenue_description', 255)->nullable();
            $table->date('paid_on')->nullable();
            $table->date('due_on')->nullable();
            $table->decimal('total_amount', 15, 2)->nullable();
            $table->timestampTz('created_at');
            $table->unique(['observation_id', 'document_digest'], 'pagtoweb_payment_list_item_uq');
            $table->index(['office_id', 'client_id', 'observation_id'], 'pagtoweb_payment_list_item_scope_idx');
        });

        Schema::create('pagtoweb_payment_list_projections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('last_observation_id')->nullable()->constrained('pagtoweb_payment_list_observations')->nullOnDelete();
            $table->foreignId('last_run_id')->nullable()->constrained('fiscal_monitoring_runs')->nullOnDelete();
            $table->timestampTz('last_valid_query_at');
            $table->string('source_provenance', 32);
            $table->timestamps();
            $table->unique(['office_id', 'client_id'], 'pagtoweb_payment_list_projection_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pagtoweb_payment_list_projections');
        Schema::dropIfExists('pagtoweb_payment_list_items');
        Schema::dropIfExists('pagtoweb_payment_list_observations');
    }
};
