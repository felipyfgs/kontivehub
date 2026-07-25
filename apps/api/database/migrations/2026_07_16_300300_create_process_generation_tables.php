<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('process_generation_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('process_template_id')->constrained('process_templates')->cascadeOnDelete();
            $table->unsignedInteger('template_lock_version');
            $table->string('competence', 7);
            $table->string('status', 32)->default('PREVIEWED');
            $table->string('payload_hash', 64);
            $table->string('idempotency_key', 64)->nullable();
            $table->json('request_snapshot');
            $table->json('preview_summary')->nullable();
            $table->foreignId('requested_by_membership_id')->constrained('office_user')->cascadeOnDelete();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('queued_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['office_id', 'idempotency_key']);
            $table->index(['office_id', 'status']);
            $table->index(['office_id', 'process_template_id', 'competence']);
            $table->index('expires_at');
        });

        Schema::create('process_generation_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('batch_id')->constrained('process_generation_batches')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('status', 32)->default('PREVIEWED');
            $table->boolean('is_blocked')->default(false);
            $table->json('preview_payload');
            $table->json('alerts')->nullable();
            $table->json('conflicts')->nullable();
            $table->foreignId('created_process_id')->nullable(); // FK adicionado após operational_processes
            $table->text('error_message')->nullable();
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->timestamps();

            $table->unique(['batch_id', 'client_id']);
            $table->index(['office_id', 'batch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('process_generation_items');
        Schema::dropIfExists('process_generation_batches');
    }
};
