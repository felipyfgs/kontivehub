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
        Schema::create('outbound_retrieval_requests', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('outbound_capture_profile_id');
            $table->bigInteger('establishment_id');
            $table->string('environment', 40);
            $table->string('model', 5);
            $table->string('direction', 10)->default('OUT');
            $table->string('competence', 7);
            $table->string('status', 32)->default('PENDING');
            $table->string('mode', 20)->default('ASSISTED');
            $table->string('external_ref', 120)->nullable();
            $table->timestampTz('requested_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('ready_at')->nullable();
            $table->timestampTz('ingested_at')->nullable();
            $table->integer('files_expected')->nullable();
            $table->integer('files_ingested')->default(0);
            $table->text('last_error')->nullable();
            $table->bigInteger('created_by')->nullable();
            $table->timestampsTz();
            $table->string('origin', 40)->default('MA_OFFICIAL_PACKAGE');
            $table->string('access_key', 50)->nullable();
            $table->bigInteger('outbound_number_state_id')->nullable();
            $table->string('recovery_status', 40)->nullable();
            $table->string('failure_reason', 60)->nullable();
            $table->smallInteger('attempt_count')->default(0);
            $table->timestampTz('next_attempt_at')->nullable();
            $table->string('correlation_id', 64)->nullable();
            $table->string('sha256', 64)->nullable();
            $table->bigInteger('dfe_document_id')->nullable();
            $table->timestampTz('due_at')->nullable();
            $table->timestampTz('target_at')->nullable();
            $table->string('deadline_source', 30)->nullable();
            $table->string('urgency_band', 20)->nullable();
            $table->string('deadline_status', 30)->nullable();
            $table->smallInteger('svrs_transaction_count')->default(0);
            $table->timestampTz('planned_at')->nullable();
            $table->timestampTz('dispatched_at')->nullable();
            $table->timestampTz('accommodation_until')->nullable();
            $table->timestampTz('captured_at')->nullable();
            $table->boolean('captured_before_due')->nullable();
            $table->string('capture_source', 40)->nullable();
            $table->string('root_cnpj', 8)->nullable();
            $table->boolean('capacity_at_risk')->default(false);
            $table->string('slot_key', 80)->nullable();
            $table->string('source_selected', 40)->nullable();
            $table->smallInteger('exchanges_reserved')->nullable();
            $table->smallInteger('exchanges_consumed')->nullable();

            $table->index(['establishment_id', 'competence', 'model'], 'outbound_retrieval_requests_establishment_id_compe_cd5373deb8');
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'access_key']);
            $table->index(['tenant_id', 'competence', 'urgency_band'], 'outbound_retrieval_requests_tenant_id_competence_u_6563c1a9d0');
            $table->index(['tenant_id', 'due_at', 'urgency_band']);
            $table->index(['tenant_id', 'next_attempt_at']);
            $table->index(['tenant_id', 'next_attempt_at', 'urgency_band'], 'outbound_retrieval_requests_tenant_id_next_attempt_0a7cc202f7');
            $table->index(['tenant_id', 'origin', 'recovery_status'], 'outbound_retrieval_requests_tenant_id_origin_recov_8fabdca4c2');
            $table->index(['tenant_id', 'root_cnpj', 'model']);
            $table->index(['tenant_id', 'slot_key']);
            $table->foreign(['created_by'])->references(['id'])->on('users')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['dfe_document_id'])->references(['id'])->on('dfe_documents')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['establishment_id'])->references(['id'])->on('establishments')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['outbound_capture_profile_id'])->references(['id'])->on('outbound_capture_profiles')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['outbound_number_state_id'])->references(['id'])->on('outbound_number_states')->onUpdate('no action')->onDelete('set null');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('outbound_retrieval_requests');
    }
};
