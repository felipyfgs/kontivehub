<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('process_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->foreignId('default_department_id')->nullable()->constrained('work_departments')->nullOnDelete();
            $table->string('default_due_rule_type', 40)->nullable();
            $table->unsignedSmallInteger('default_due_rule_value')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('lock_version')->default(1);
            $table->foreignId('created_by_membership_id')->nullable()->constrained('office_user')->nullOnDelete();
            $table->timestamps();

            $table->unique(['office_id', 'name']);
            $table->index(['office_id', 'is_active']);
        });

        Schema::create('process_template_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('process_template_id')->constrained('process_templates')->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order');
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('due_rule_type', 40)->nullable();
            $table->unsignedSmallInteger('due_rule_value')->nullable();
            $table->foreignId('default_department_id')->nullable()->constrained('work_departments')->nullOnDelete();
            $table->foreignId('default_assignee_membership_id')->nullable()->constrained('office_user')->nullOnDelete();
            $table->boolean('is_required')->default(true);
            $table->boolean('is_critical')->default(false);
            $table->boolean('requires_evidence')->default(false);
            $table->timestamps();

            $table->unique(['process_template_id', 'sort_order']);
            $table->index(['office_id', 'process_template_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('process_template_tasks');
        Schema::dropIfExists('process_templates');
    }
};
