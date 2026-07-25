<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mei_automation_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fiscal_monitoring_run_id')->nullable()
                ->constrained('fiscal_monitoring_runs')->nullOnDelete();
            $table->foreignId('fiscal_mutation_operation_id')->nullable()
                ->constrained('fiscal_mutation_operations')->nullOnDelete();
            $table->uuid('external_job_id')->nullable()->unique();
            $table->string('operation_key', 80);
            $table->string('provider', 24);
            $table->string('status', 32);
            $table->string('idempotency_key', 160);
            $table->string('request_fingerprint', 64);
            $table->unsignedSmallInteger('attempt_number')->default(1);
            $table->string('source_provenance', 32)->nullable();
            $table->string('verification_kind', 32)->nullable();
            $table->string('portal_version', 40)->nullable();
            $table->string('parser_version', 40)->nullable();
            $table->string('captcha_driver', 32)->nullable();
            $table->unsignedBigInteger('captcha_cost_micros')->default(0);
            $table->string('fallback_reason', 80)->nullable();
            $table->string('error_code', 80)->nullable();
            $table->string('error_message', 240)->nullable();
            $table->json('safe_metadata')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->unique(
                ['office_id', 'idempotency_key', 'attempt_number'],
                'maa_office_idempotency_attempt_uq',
            );
            $table->index(['office_id', 'client_id', 'operation_key'], 'maa_office_client_operation_idx');
            $table->index(['office_id', 'status', 'created_at'], 'maa_office_status_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mei_automation_attempts');
    }
};
