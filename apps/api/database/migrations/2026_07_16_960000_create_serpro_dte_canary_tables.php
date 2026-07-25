<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedido de canário DTE, controle DISABLED|CANARY|LIMITED e reconciliação.
 * Coordenadas da operação são colunas imutáveis no pedido (não vêm do client).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('serpro_dte_controls')) {
            Schema::create('serpro_dte_controls', function (Blueprint $table): void {
                $table->id();
                $table->string('operation_key', 120)->default('dte.consultar');
                $table->string('mode', 20)->default('DISABLED');
                $table->unsignedBigInteger('pilot_office_id')->nullable();
                $table->unsignedBigInteger('pilot_client_id')->nullable();
                $table->unsignedInteger('limited_max_quantity')->nullable();
                $table->unsignedInteger('limited_used_quantity')->default(0);
                $table->string('cycle_code', 40)->nullable();
                $table->timestampTz('promoted_at')->nullable();
                $table->unsignedBigInteger('promoted_by_user_id')->nullable();
                $table->timestampTz('disabled_at')->nullable();
                $table->unsignedBigInteger('disabled_by_user_id')->nullable();
                $table->string('disable_reason', 500)->nullable();
                $table->unsignedTinyInteger('alert_percent')->default(80);
                $table->boolean('alert_80_emitted')->default(false);
                $table->boolean('alert_100_emitted')->default(false);
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique('operation_key', 'serpro_dte_control_op_uq');
                $table->index(['mode', 'pilot_office_id'], 'serpro_dte_control_mode_office_idx');
            });
        }

        if (! Schema::hasTable('serpro_dte_canary_requests')) {
            Schema::create('serpro_dte_canary_requests', function (Blueprint $table): void {
                $table->id();
                $table->string('environment', 20)->default('PRODUCTION');
                $table->string('status', 32)->default('DRAFT');

                // Alvo server-side imutável após TARGET_SET
                $table->unsignedBigInteger('office_id')->nullable();
                $table->unsignedBigInteger('client_id')->nullable();
                $table->unsignedBigInteger('selected_by_user_id')->nullable();
                $table->timestampTz('selected_at')->nullable();

                // Coordenadas DTE imutáveis (hardcoded no serviço; espelhadas para auditoria)
                $table->string('operation_key', 120)->default('dte.consultar');
                $table->string('id_sistema', 40)->default('DTE');
                $table->string('id_servico', 80)->default('CONSULTASITUACAODTE111');
                $table->string('service_version', 20)->default('1.0');
                $table->string('functional_route', 40)->default('/Consultar');
                $table->string('required_proxy_power', 20)->default('00050');

                // Aprovações dual (usuários distintos)
                $table->unsignedBigInteger('owner_approver_user_id')->nullable();
                $table->timestampTz('owner_approved_at')->nullable();
                $table->unsignedBigInteger('office_admin_approver_user_id')->nullable();
                $table->timestampTz('office_admin_approved_at')->nullable();

                // Tentativa idempotente
                $table->string('idempotency_key', 190)->nullable();
                $table->string('correlation_id', 64)->nullable();
                $table->string('request_tag', 32)->nullable();
                $table->unsignedBigInteger('attempt_id')->nullable();
                $table->unsignedTinyInteger('consumption_quantity')->default(0);
                $table->string('result_status', 40)->nullable();
                $table->timestampTz('dispatched_at')->nullable();
                $table->timestampTz('finished_at')->nullable();

                // Reconciliação manual
                $table->string('reconciliation_reference', 200)->nullable();
                $table->string('reconciliation_summary', 1000)->nullable();
                $table->unsignedBigInteger('reconciled_by_user_id')->nullable();
                $table->timestampTz('reconciled_at')->nullable();

                $table->unsignedBigInteger('created_by_user_id')->nullable();
                $table->timestampTz('expires_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique('idempotency_key', 'serpro_dte_canary_idemp_uq');
                $table->index(['status', 'office_id'], 'serpro_dte_canary_status_office_idx');
                $table->index(['environment', 'office_id', 'client_id'], 'serpro_dte_canary_target_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('serpro_dte_canary_requests');
        Schema::dropIfExists('serpro_dte_controls');
    }
};
