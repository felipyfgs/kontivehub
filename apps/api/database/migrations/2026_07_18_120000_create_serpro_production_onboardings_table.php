<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('serpro_production_onboardings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('environment', 20)->default('PRODUCTION');
            $table->string('idempotency_key', 96);
            $table->string('status', 40)->default('PENDING');
            $table->string('current_step', 64)->default('VALIDATE_INPUT');
            $table->json('completed_steps')->nullable();
            $table->string('consent_version', 80);
            $table->string('consent_text_sha256', 64);
            $table->timestampTz('consented_at');
            $table->string('correlation_id', 64);
            $table->foreignId('serpro_credential_version_id')->nullable()
                ->constrained('serpro_credential_versions')->nullOnDelete();
            $table->foreignId('office_serpro_authorization_id')->nullable()
                ->constrained('office_serpro_authorizations')->nullOnDelete();
            $table->foreignId('serpro_rollout_approval_id')->nullable()
                ->constrained('serpro_rollout_approvals')->nullOnDelete();
            $table->foreignId('initial_mailbox_run_id')->nullable()
                ->constrained('fiscal_monitoring_runs')->nullOnDelete();
            $table->string('consumer_key_hint', 40)->nullable();
            $table->string('certificate_fingerprint_sha256', 64)->nullable();
            $table->string('contractor_cnpj_masked', 32)->nullable();
            $table->timestampTz('certificate_valid_to')->nullable();
            $table->string('error_code', 80)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->json('required_actions')->nullable();
            $table->json('metadata')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['office_id', 'environment', 'idempotency_key'], 'serpro_prod_onboard_office_env_key_uq');
            $table->index(['office_id', 'environment', 'status'], 'serpro_prod_onboard_office_env_status_idx');
            $table->index(['status', 'current_step'], 'serpro_prod_onboard_status_step_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('serpro_production_onboardings');
    }
};
