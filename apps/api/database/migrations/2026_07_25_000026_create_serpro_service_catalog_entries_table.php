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
        Schema::create('serpro_service_catalog_entries', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('catalog_version');
            $table->string('environment', 20);
            $table->string('solution_code', 80);
            $table->string('service_code', 120);
            $table->string('operation_code', 120);
            $table->string('label');
            $table->boolean('is_mutating')->default(false);
            $table->boolean('is_enabled')->default(true);
            $table->string('required_proxy_power', 120)->nullable();
            $table->string('billable_class', 40);
            $table->integer('cache_ttl_seconds')->nullable();
            $table->integer('rate_limit_per_minute')->nullable();
            $table->string('coverage', 40)->default('KNOWN');
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('effective_from')->nullable();
            $table->timestampTz('effective_to')->nullable();
            $table->timestampsTz();
            $table->string('operation_key', 120)->nullable()->index();
            $table->string('id_sistema', 80)->nullable();
            $table->string('id_servico', 120)->nullable();
            $table->string('versao_sistema', 20)->nullable();
            $table->string('functional_route', 20)->nullable();
            $table->string('official_state', 30)->nullable();
            $table->string('platform_support', 30)->nullable();
            $table->string('dados_mode', 20)->nullable();

            $table->unique(['catalog_version', 'environment', 'solution_code', 'service_code', 'operation_code'], 'serpro_service_catalog_entries_catalog_version_env_51c7831505');
            $table->index(['environment', 'is_enabled']);
            $table->index(['solution_code', 'service_code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('serpro_service_catalog_entries');
    }
};
