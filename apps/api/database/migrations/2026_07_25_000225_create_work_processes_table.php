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
        Schema::create('work_processes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('client_id');
            $table->bigInteger('work_process_template_id')->nullable();
            $table->bigInteger('generation_batch_id')->nullable();
            $table->string('origin', 20)->default('MANUAL');
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('competence', 16);
            $table->date('due_date')->nullable();
            $table->date('target_due_date')->nullable();
            $table->boolean('subject_to_fine')->default(false);
            $table->bigInteger('work_department_id')->nullable();
            $table->bigInteger('assignee_membership_id')->nullable();
            $table->string('status', 32)->default('A_FAZER');
            $table->jsonb('template_snapshot')->nullable();
            $table->integer('lock_version')->default(1);
            $table->bigInteger('created_by_membership_id')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();
            $table->string('monitoring_module_key', 40)->nullable();
            $table->string('reference_period_type', 16)->nullable();
            $table->date('reference_period_start')->nullable();
            $table->date('reference_period_end')->nullable();

            $table->index(['tenant_id', 'reference_period_type', 'competence']);
            $table->index(['tenant_id', 'monitoring_module_key', 'status']);
            $table->index(['tenant_id', 'assignee_membership_id']);
            $table->index(['tenant_id', 'client_id']);
            $table->index(['tenant_id', 'competence']);
            $table->index(['tenant_id', 'due_date']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'work_department_id']);
            $table->foreign(['assignee_membership_id'])->references(['id'])->on('tenant_memberships')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['client_id'])->references(['id'])->on('clients')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['created_by_membership_id'])->references(['id'])->on('tenant_memberships')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['generation_batch_id'])->references(['id'])->on('work_process_generation_batches')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['work_process_template_id'])->references(['id'])->on('work_process_templates')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['work_department_id'])->references(['id'])->on('work_departments')->onUpdate('no action')->onDelete('set null');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_processes');
    }
};
