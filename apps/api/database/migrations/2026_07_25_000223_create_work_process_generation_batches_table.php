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
        Schema::create('work_process_generation_batches', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('work_process_template_id');
            $table->integer('template_lock_version');
            $table->string('competence', 16);
            $table->string('status', 32)->default('PREVIEWED');
            $table->string('payload_hash', 64);
            $table->string('idempotency_key', 64)->nullable();
            $table->jsonb('request_snapshot');
            $table->jsonb('preview_summary')->nullable();
            $table->timestampTz('expires_at')->nullable()->index();
            $table->timestampTz('queued_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->bigInteger('requested_by_membership_id')->nullable();
            $table->string('reference_period_type', 16)->nullable();
            $table->date('reference_period_start')->nullable();
            $table->date('reference_period_end')->nullable();

            $table->index(['tenant_id', 'work_process_template_id', 'reference_period_type', 'competence'], 'work_process_generation_batches_tenant_id_work_pro_c7741098b0');
            $table->unique(['tenant_id', 'idempotency_key'], 'work_process_generation_batches_tenant_id_idempote_3e19c5b4eb');
            $table->index(['tenant_id', 'work_process_template_id', 'competence'], 'work_process_generation_batches_tenant_id_work_pro_457a8de3c1');
            $table->index(['tenant_id', 'status']);
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['work_process_template_id'], 'work_process_generation_batches_work_process_templ_51630433fb')->references(['id'])->on('work_process_templates')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['requested_by_membership_id'], 'work_process_generation_batches_requested_by_membe_8ecbe050ba')->references(['id'])->on('tenant_memberships')->onUpdate('no action')->onDelete('set null');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_process_generation_batches');
    }
};
