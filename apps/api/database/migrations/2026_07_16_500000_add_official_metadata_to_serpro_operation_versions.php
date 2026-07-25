<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('serpro_operation_versions', function (Blueprint $table): void {
            $table->string('auth_mode', 50)->nullable();
            $table->string('proxy_rule', 50)->nullable();
            $table->json('required_proxy_powers')->nullable();
            $table->string('official_state', 40)->nullable();
            $table->string('platform_support', 40)->nullable();
            $table->string('monitoring_module', 50)->nullable();
            $table->boolean('is_mutating')->default(true);
            $table->string('billable_class', 40)->nullable();
            $table->string('dados_mode', 30)->nullable();
            $table->string('async_policy', 50)->nullable();
            $table->json('request_schema')->nullable();
            $table->json('response_schema')->nullable();
            $table->json('source_evidence')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('serpro_operation_versions', function (Blueprint $table): void {
            $table->dropColumn([
                'auth_mode',
                'proxy_rule',
                'required_proxy_powers',
                'official_state',
                'platform_support',
                'monitoring_module',
                'is_mutating',
                'billable_class',
                'dados_mode',
                'async_policy',
                'request_schema',
                'response_schema',
                'source_evidence',
            ]);
        });
    }
};
