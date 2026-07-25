<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operational_comments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('operational_process_id')->nullable()->constrained('operational_processes')->cascadeOnDelete();
            $table->foreignId('operational_task_id')->nullable()->constrained('operational_tasks')->cascadeOnDelete();
            $table->foreignId('author_membership_id')->constrained('office_user')->cascadeOnDelete();
            $table->text('body');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['office_id', 'operational_process_id', 'created_at']);
            $table->index(['office_id', 'operational_task_id', 'created_at']);
        });

        Schema::create('operational_task_evidences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('operational_task_id')->constrained('operational_tasks')->cascadeOnDelete();
            $table->string('original_filename', 255);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('byte_size');
            $table->string('sha256', 64);
            // Identificador opaco do cofre — nunca expor via API/resource
            $table->string('vault_object_id', 26);
            $table->foreignId('uploaded_by_membership_id')->constrained('office_user')->cascadeOnDelete();
            $table->text('removal_reason')->nullable();
            $table->timestampTz('removed_at')->nullable();
            $table->foreignId('removed_by_membership_id')->nullable()->constrained('office_user')->nullOnDelete();
            $table->timestamps();

            $table->index(['office_id', 'operational_task_id']);
            $table->index(['office_id', 'sha256']);
            $table->unique(['office_id', 'vault_object_id']);
        });

        // Export CSV operacional — separado do Export ZIP fiscal
        Schema::create('operational_exports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by_membership_id')->constrained('office_user')->cascadeOnDelete();
            $table->string('status', 32)->default('PENDING');
            $table->json('filters_snapshot');
            // path interno — Hidden no model, nunca no resource
            $table->string('storage_path')->nullable();
            $table->unsignedBigInteger('byte_size')->nullable();
            $table->unsignedInteger('row_count')->default(0);
            $table->text('error_message')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestamps();

            $table->index(['office_id', 'requested_by_membership_id', 'status']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_exports');
        Schema::dropIfExists('operational_task_evidences');
        Schema::dropIfExists('operational_comments');
    }
};
