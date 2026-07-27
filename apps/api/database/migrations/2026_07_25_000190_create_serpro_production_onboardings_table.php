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
        Schema::create('serpro_production_onboardings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('actor_user_id');
            $table->string('environment', 20)->default('PRODUCTION');
            $table->string('idempotency_key', 96);
            $table->string('status', 40)->default('PENDING');
            $table->string('current_step', 64)->default('VALIDATE_INPUT');
            $table->jsonb('completed_steps')->nullable();
            $table->string('consent_version', 80);
            $table->string('consent_text_sha256', 64);
            $table->timestampTz('consented_at');
            $table->string('correlation_id', 64);
            $table->bigInteger('serpro_credential_version_id')->nullable();
            $table->bigInteger('tenant_serpro_authorization_id')->nullable();
            $table->bigInteger('serpro_rollout_approval_id')->nullable();
            $table->bigInteger('initial_mailbox_run_id')->nullable();
            $table->string('consumer_key_hint', 40)->nullable();
            $table->string('certificate_fingerprint_sha256', 64)->nullable();
            $table->string('contractor_cnpj_masked', 32)->nullable();
            $table->timestampTz('certificate_valid_to')->nullable();
            $table->string('error_code', 80)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->jsonb('required_actions')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'environment', 'idempotency_key'], 'serpro_production_onboardings_tenant_id_environmen_00e66429b3');
            $table->index(['tenant_id', 'environment', 'status'], 'serpro_production_onboardings_tenant_id_environmen_ac19c93d51');
            $table->index(['status', 'current_step']);
            $table->foreign(['actor_user_id'])->references(['id'])->on('users')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['initial_mailbox_run_id'])->references(['id'])->on('fiscal_monitoring_runs')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['tenant_serpro_authorization_id'], 'serpro_production_onboardings_tenant_serpro_author_6b68394046')->references(['id'])->on('tenant_serpro_authorizations')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['serpro_credential_version_id'], 'serpro_production_onboardings_serpro_credential_ve_d1113ba878')->references(['id'])->on('serpro_credential_versions')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['serpro_rollout_approval_id'], 'serpro_production_onboardings_serpro_rollout_appro_c08a22a165')->references(['id'])->on('serpro_rollout_approvals')->onUpdate('no action')->onDelete('set null');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('serpro_production_onboardings');
    }
};
