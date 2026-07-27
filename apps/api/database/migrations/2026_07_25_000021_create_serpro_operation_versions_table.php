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
        Schema::create('serpro_operation_versions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('serpro_operation_id');
            $table->string('system_code', 40);
            $table->string('service_code', 80);
            $table->string('operation_code', 80);
            $table->string('id_sistema', 40)->nullable();
            $table->string('id_servico', 80)->nullable();
            $table->string('versao_sistema', 40)->nullable();
            $table->string('functional_route', 40)->nullable();
            $table->timestampTz('effective_from')->nullable();
            $table->timestampTz('effective_to')->nullable();
            $table->string('source_catalog', 40)->nullable();
            $table->bigInteger('source_row_id')->nullable();
            $table->timestampsTz();
            $table->string('auth_mode', 50)->nullable();
            $table->string('proxy_rule', 50)->nullable();
            $table->jsonb('required_proxy_powers')->nullable();
            $table->string('official_state', 40)->nullable();
            $table->string('platform_support', 40)->nullable();
            $table->string('monitoring_module', 50)->nullable();
            $table->boolean('is_mutating')->default(true);
            $table->string('billable_class', 40)->nullable();
            $table->string('dados_mode', 30)->nullable();
            $table->string('async_policy', 50)->nullable();
            $table->jsonb('request_schema')->nullable();
            $table->jsonb('response_schema')->nullable();
            $table->jsonb('source_evidence')->nullable();

            $table->index(['serpro_operation_id', 'system_code', 'service_code', 'operation_code'], 'serpro_operation_versions_serpro_operation_id_syst_d231d08f2d');
            $table->unique(['source_catalog', 'source_row_id']);
            $table->index(['system_code', 'service_code', 'operation_code'], 'serpro_operation_versions_system_code_service_code_64c785d9af');
            $table->foreign(['serpro_operation_id'])->references(['id'])->on('serpro_operations')->onUpdate('no action')->onDelete('restrict');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('serpro_operation_versions');
    }
};
