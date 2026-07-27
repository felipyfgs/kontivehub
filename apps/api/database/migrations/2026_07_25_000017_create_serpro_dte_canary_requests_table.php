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
        Schema::create('serpro_dte_canary_requests', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('environment', 20)->default('PRODUCTION');
            $table->string('status', 32)->default('DRAFT');
            $table->bigInteger('tenant_id')->nullable();
            $table->bigInteger('client_id')->nullable();
            $table->bigInteger('selected_by_user_id')->nullable();
            $table->timestampTz('selected_at')->nullable();
            $table->string('operation_key', 120)->default('dte.consultar');
            $table->string('id_sistema', 40)->default('DTE');
            $table->string('id_servico', 80)->default('CONSULTASITUACAODTE111');
            $table->string('service_version', 20)->default('1.0');
            $table->string('functional_route', 40)->default('/Consultar');
            $table->string('required_proxy_power', 20)->default('00050');
            $table->bigInteger('owner_approver_user_id')->nullable();
            $table->timestampTz('owner_approved_at')->nullable();
            $table->bigInteger('tenant_admin_approver_user_id')->nullable();
            $table->timestampTz('tenant_admin_approved_at')->nullable();
            $table->string('idempotency_key', 190)->nullable()->unique();
            $table->string('correlation_id', 64)->nullable();
            $table->string('request_tag', 32)->nullable();
            $table->bigInteger('attempt_id')->nullable();
            $table->smallInteger('consumption_quantity')->default(0);
            $table->string('result_status', 40)->nullable();
            $table->timestampTz('dispatched_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->string('reconciliation_reference', 200)->nullable();
            $table->string('reconciliation_summary', 1000)->nullable();
            $table->bigInteger('reconciled_by_user_id')->nullable();
            $table->timestampTz('reconciled_at')->nullable();
            $table->bigInteger('created_by_user_id')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'tenant_id']);
            $table->index(['environment', 'tenant_id', 'client_id'], 'serpro_dte_canary_requests_environment_tenant_id_c_e41b8a8231');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('serpro_dte_canary_requests');
    }
};
