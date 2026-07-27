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
        Schema::create('serpro_eventos_run_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('serpro_eventos_run_id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('client_id')->nullable();
            $table->string('ni_fingerprint', 64);
            $table->string('classification', 32);
            $table->date('event_date')->nullable();
            $table->string('processing_status', 24)->default('PENDING');
            $table->bigInteger('directed_run_id')->nullable();
            $table->string('error_code', 80)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'processing_status']);
            $table->unique(['serpro_eventos_run_id', 'ni_fingerprint'], 'serpro_eventos_run_items_serpro_eventos_run_id_ni__8d129a5a54');
            $table->foreign(['client_id'])->references(['id'])->on('clients')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['directed_run_id'])->references(['id'])->on('fiscal_monitoring_runs')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['serpro_eventos_run_id'])->references(['id'])->on('serpro_eventos_runs')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('serpro_eventos_run_items');
    }
};
