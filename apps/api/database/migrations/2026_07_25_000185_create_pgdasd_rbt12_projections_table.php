<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('pgdasd_rbt12_projections', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('client_id');
            $table->bigInteger('projection_id');
            $table->string('source_reference_key', 64);
            $table->string('source_das_number', 80)->nullable();
            $table->string('source_declaration_number', 80)->nullable();
            $table->timestampTz('source_transmitted_at')->nullable();
            $table->bigInteger('internal_market_cents')->nullable();
            $table->bigInteger('external_market_cents')->nullable();
            $table->bigInteger('total_cents')->nullable();
            $table->string('status', 20)->default('PENDING');
            $table->timestampTz('attempted_at')->nullable();
            $table->timestampTz('extracted_at')->nullable();
            $table->text('sanitized_error')->nullable();
            $table->string('parser_version', 40)->nullable();
            $table->bigInteger('source_artifact_id')->nullable();
            $table->bigInteger('source_run_id')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'client_id', 'projection_id', 'source_reference_key'], 'pgdasd_rbt12_projections_tenant_id_client_id_proje_0002e42e16');
            $table->index(['tenant_id', 'client_id', 'status']);
            $table->foreign(['client_id'])->references(['id'])->on('clients')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['source_run_id'])->references(['id'])->on('fiscal_monitoring_runs')->onUpdate('no action')->onDelete('set null');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pgdasd_rbt12_projections');
    }
};
