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
        Schema::create('work_tasks', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('work_process_id');
            $table->smallInteger('sort_order');
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('status', 32)->default('A_FAZER');
            $table->date('due_date')->nullable();
            $table->date('target_due_date')->nullable();
            $table->bigInteger('work_department_id')->nullable();
            $table->bigInteger('assignee_membership_id')->nullable();
            $table->boolean('is_required')->default(true);
            $table->boolean('is_critical')->default(false);
            $table->boolean('requires_evidence')->default(false);
            $table->text('block_reason')->nullable();
            $table->integer('lock_version')->default(1);
            $table->bigInteger('started_by_membership_id')->nullable();
            $table->bigInteger('completed_by_membership_id')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'assignee_membership_id', 'status']);
            $table->index(['tenant_id', 'due_date']);
            $table->index(['tenant_id', 'work_process_id']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'work_department_id', 'status']);
            $table->unique(['work_process_id', 'sort_order']);
            $table->foreign(['assignee_membership_id'])->references(['id'])->on('tenant_memberships')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['completed_by_membership_id'])->references(['id'])->on('tenant_memberships')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['work_process_id'])->references(['id'])->on('work_processes')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['started_by_membership_id'])->references(['id'])->on('tenant_memberships')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['work_department_id'])->references(['id'])->on('work_departments')->onUpdate('no action')->onDelete('set null');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_tasks');
    }
};
