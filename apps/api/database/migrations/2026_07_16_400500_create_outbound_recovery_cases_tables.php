<?php

use App\Support\FiscalDataModel\MigrationPrecondition;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Caso de recuperação outbound (autoridade de prazo/completude) + tentativas por fonte.
 * ma_outbound_retrieval_requests permanece até cutover (compat).
 */
return new class extends Migration
{
    public function up(): void
    {
        MigrationPrecondition::tablesExist(['offices', 'clients'], 'outbound_recovery_cases');
        MigrationPrecondition::tableMissing('outbound_recovery_cases', 'outbound_recovery_cases');

        Schema::create('outbound_recovery_cases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->constrained('offices')->restrictOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->restrictOnDelete();
            $table->string('access_key', 50)->nullable();
            $table->string('document_family', 20)->nullable(); // NFE, NFCE, …
            $table->string('identity_key', 120); // chave natural fiscal
            $table->string('urgency', 32)->default('NORMAL'); // sem CAPTURED
            $table->string('completeness', 32)->default('OPEN'); // OPEN|SATISFIED|FAILED|CANCELLED
            $table->timestampTz('deadline_at')->nullable();
            $table->foreignId('satisfying_acquisition_id')->nullable();
            $table->unsignedBigInteger('legacy_ma_request_id')->nullable()->index();
            $table->json('metadata_sanitized')->nullable();
            $table->timestamps();

            $table->unique(['office_id', 'identity_key'], 'outbound_recovery_case_identity_unique');
            $table->index(['office_id', 'completeness', 'deadline_at']);
        });

        Schema::create('outbound_recovery_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->constrained('offices')->restrictOnDelete();
            $table->foreignId('outbound_recovery_case_id')
                ->constrained('outbound_recovery_cases')
                ->restrictOnDelete();
            $table->string('source', 40);
            $table->string('request_tag', 64)->nullable();
            $table->string('routing_decision', 40)->nullable();
            $table->string('result', 40)->default('PENDING');
            $table->unsignedInteger('estimated_cost_micros')->nullable();
            $table->string('error_code_sanitized', 80)->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->foreignId('document_acquisition_id')->nullable()
                ->constrained('document_acquisitions')->nullOnDelete();
            $table->unsignedBigInteger('legacy_attempt_id')->nullable()->index();
            $table->timestamps();

            $table->index(['office_id', 'outbound_recovery_case_id', 'source']);
            $table->unique(
                ['outbound_recovery_case_id', 'source', 'request_tag'],
                'outbound_attempt_case_source_tag_unique',
            );
        });

        if (Schema::hasTable('document_acquisitions')) {
            Schema::table('outbound_recovery_cases', function (Blueprint $table): void {
                $table->foreign('satisfying_acquisition_id', 'outbound_case_satisfying_acq_fk')
                    ->references('id')
                    ->on('document_acquisitions')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_recovery_attempts');
        Schema::dropIfExists('outbound_recovery_cases');
    }
};
