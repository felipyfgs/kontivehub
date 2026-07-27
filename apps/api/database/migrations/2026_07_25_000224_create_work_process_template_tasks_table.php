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
        Schema::create('work_process_template_tasks', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('work_process_template_id');
            $table->smallInteger('sort_order');
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('due_rule_type', 40)->nullable();
            $table->smallInteger('due_rule_value')->nullable();
            $table->bigInteger('default_department_id')->nullable();
            $table->bigInteger('default_assignee_membership_id')->nullable();
            $table->boolean('is_required')->default(true);
            $table->boolean('is_critical')->default(false);
            $table->boolean('requires_evidence')->default(false);
            $table->timestampsTz();

            $table->index(['tenant_id', 'work_process_template_id'], 'work_process_template_tasks_tenant_id_work_process_d73bc1ea24');
            $table->unique(['work_process_template_id', 'sort_order'], 'work_process_template_tasks_work_process_template__6c61735047');
            $table->foreign(['default_assignee_membership_id'], 'work_process_template_tasks_default_assignee_membe_cc79414872')->references(['id'])->on('tenant_memberships')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['default_department_id'])->references(['id'])->on('work_departments')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['work_process_template_id'])->references(['id'])->on('work_process_templates')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_process_template_tasks');
    }
};
