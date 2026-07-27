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
        Schema::create('work_process_templates', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->bigInteger('default_department_id')->nullable();
            $table->string('default_due_rule_type', 40)->nullable();
            $table->smallInteger('default_due_rule_value')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('lock_version')->default(1);
            $table->bigInteger('created_by_membership_id')->nullable();
            $table->timestampsTz();
            $table->string('catalog_key', 80)->nullable();
            $table->smallInteger('catalog_version')->nullable();
            $table->string('monitoring_module_key', 40)->nullable();
            $table->jsonb('audience_rules')->nullable();
            $table->boolean('recurrence_enabled')->default(false);
            $table->string('recurrence_frequency', 16)->nullable();
            $table->smallInteger('generation_day')->default(1);
            $table->smallInteger('anchor_month')->nullable();
            $table->string('period_offset', 16)->default('PREVIOUS');
            $table->timestampTz('next_run_at')->nullable();
            $table->bigInteger('recurrence_owner_membership_id')->nullable();

            $table->unique(['tenant_id', 'catalog_key']);
            $table->index(['tenant_id', 'is_active']);
            $table->unique(['tenant_id', 'name']);
            $table->index(['tenant_id', 'monitoring_module_key']);
            $table->index(['recurrence_enabled', 'is_active', 'next_run_at'], 'work_process_templates_recurrence_enabled_is_activ_7f2f1a2c55');
            $table->foreign(['created_by_membership_id'])->references(['id'])->on('tenant_memberships')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['default_department_id'])->references(['id'])->on('work_departments')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['recurrence_owner_membership_id'])->references(['id'])->on('tenant_memberships')->onUpdate('no action')->onDelete('set null');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_process_templates');
    }
};
