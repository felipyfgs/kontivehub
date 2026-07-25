<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Readiness runs hierárquicos, evidências/gates e snapshots documentais oficiais.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('serpro_document_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('source_key', 80);
            $table->string('title', 200);
            $table->string('url', 1000)->nullable();
            $table->string('content_sha256', 64);
            $table->string('document_type', 40);
            $table->string('revision', 80)->nullable();
            $table->date('retrieved_on')->nullable();
            $table->json('affected_capabilities')->nullable();
            $table->string('segregation_class', 40)->default('PRODUCTION');
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['source_key', 'content_sha256'], 'serpro_doc_snap_key_hash_uq');
            $table->index(['source_key', 'retrieved_on']);
        });

        Schema::create('serpro_external_gates', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 80)->unique();
            $table->string('status', 32)->default('OPEN');
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('ticket_ref', 120)->nullable();
            $table->string('evidence_ref', 200)->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('answered_at')->nullable();
            $table->timestampTz('accepted_at')->nullable();
            $table->string('answer_summary', 1000)->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('serpro_readiness_runs', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 20);
            $table->string('environment', 20);
            $table->foreignId('serpro_contract_id')->nullable()->constrained('serpro_contracts')->nullOnDelete();
            $table->foreignId('office_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('operation_key', 120)->nullable();
            $table->string('highest_gate', 40)->nullable();
            $table->string('result', 20);
            $table->boolean('live_evidence')->default(false);
            $table->string('trigger', 40)->default('MANUAL');
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->json('summary')->nullable();
            $table->timestamps();

            $table->index(['scope', 'environment', 'result'], 'serpro_ready_run_scope_env_res_idx');
            $table->index(['office_id', 'environment']);
            $table->index(['expires_at']);
        });

        Schema::create('serpro_readiness_evidences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('serpro_readiness_run_id')
                ->constrained('serpro_readiness_runs')
                ->cascadeOnDelete();
            $table->string('gate', 40);
            $table->string('scope', 20);
            $table->string('status', 32);
            $table->boolean('live_evidence')->default(false);
            $table->string('fingerprint', 64)->nullable();
            $table->string('document_revision', 80)->nullable();
            $table->string('sanitized_reason', 500)->nullable();
            $table->timestampTz('observed_at');
            $table->timestampTz('valid_until')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['serpro_readiness_run_id', 'gate'], 'serpro_ready_ev_run_gate_idx');
            $table->index(['gate', 'status']);
        });

        Schema::create('serpro_rollout_approvals', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('action', 40);
            $table->string('environment', 20);
            $table->foreignId('office_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 32)->default('PENDING');
            $table->string('reason', 500)->nullable();
            $table->unsignedBigInteger('requested_by_user_id')->nullable();
            $table->unsignedBigInteger('first_approver_user_id')->nullable();
            $table->unsignedBigInteger('second_approver_user_id')->nullable();
            $table->timestampTz('first_approved_at')->nullable();
            $table->timestampTz('second_approved_at')->nullable();
            $table->timestampTz('executed_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->json('context')->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id', 'action']);
            $table->index(['status', 'environment']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('serpro_rollout_approvals');
        Schema::dropIfExists('serpro_readiness_evidences');
        Schema::dropIfExists('serpro_readiness_runs');
        Schema::dropIfExists('serpro_external_gates');
        Schema::dropIfExists('serpro_document_snapshots');
    }
};
