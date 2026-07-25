<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fgts_digital_representations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('office_credential_id')->nullable()->constrained()->nullOnDelete();
            $table->string('credential_source', 16);
            $table->string('profile_type', 32)->default('PROCURADOR_PJ');
            $table->string('target_identifier_hash', 64);
            $table->string('status', 20)->default('PENDING');
            $table->timestampTz('valid_from')->nullable();
            $table->timestampTz('valid_to')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('verified_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['office_id', 'client_id', 'status'], 'fgts_rep_tenant_status_idx');
        });

        Schema::create('fgts_digital_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('representation_id')->nullable()->constrained('fgts_digital_representations')->nullOnDelete();
            $table->string('credential_source', 16);
            $table->string('credential_fingerprint', 64);
            $table->string('profile_type', 32);
            $table->string('target_identifier_hash', 64);
            $table->string('contract_version', 16)->default('1');
            $table->string('status', 32)->default('READY');
            $table->string('vault_object_id', 26)->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('last_used_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['office_id', 'client_id', 'profile_type', 'status', 'expires_at'], 'fgts_session_tenant_ready_idx');
        });

        Schema::create('fgts_digital_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('session_id')->nullable()->constrained('fgts_digital_sessions')->nullOnDelete();
            $table->foreignId('fiscal_mutation_operation_id')->nullable()->constrained('fiscal_mutation_operations')->nullOnDelete();
            $table->foreignId('tax_guide_id')->nullable()->constrained('tax_guides')->nullOnDelete();
            $table->foreignId('tax_guide_version_id')->nullable()->constrained('tax_guide_versions')->nullOnDelete();
            $table->string('operation', 32);
            $table->string('guide_type', 24)->nullable();
            $table->string('status', 40)->default('PENDING');
            $table->string('code', 80)->nullable();
            $table->string('idempotency_key', 160);
            $table->string('request_digest', 64);
            $table->string('request_vault_object_id', 26)->nullable();
            $table->string('preview_token_hash', 64)->nullable();
            $table->string('confirmation_phrase', 160)->nullable();
            $table->timestampTz('preview_expires_at')->nullable();
            $table->json('request_sanitized')->nullable();
            $table->json('result_sanitized')->nullable();
            $table->string('correlation_id', 64)->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['office_id', 'idempotency_key'], 'fgts_run_tenant_idem_uq');
            $table->index(['office_id', 'client_id', 'created_at'], 'fgts_run_tenant_client_idx');
            $table->index(['office_id', 'status', 'created_at'], 'fgts_run_tenant_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fgts_digital_runs');
        Schema::dropIfExists('fgts_digital_sessions');
        Schema::dropIfExists('fgts_digital_representations');
    }
};
