<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_cursors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('establishment_id')->constrained()->cascadeOnDelete();
            $table->string('environment', 40);
            $table->unsignedBigInteger('last_nsu')->default(0);
            $table->string('status', 32)->default('IDLE');
            $table->unsignedInteger('consecutive_decode_failures')->default(0);
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('next_sync_at')->nullable();
            $table->timestampTz('last_success_at')->nullable();
            $table->timestampTz('locked_at')->nullable();
            $table->string('lock_owner')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['establishment_id', 'environment']);
            $table->index(['status', 'next_sync_at']);
        });

        Schema::create('sync_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sync_cursor_id')->constrained()->cascadeOnDelete();
            $table->string('status', 32);
            $table->string('trigger', 20)->default('SCHEDULED');
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('pages_processed')->default(0);
            $table->unsignedInteger('documents_persisted')->default(0);
            $table->unsignedBigInteger('from_nsu')->default(0);
            $table->unsignedBigInteger('to_nsu')->default(0);
            $table->text('error_message')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('dfe_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->string('sha256', 64);
            $table->string('document_type', 20);
            $table->string('schema_version')->nullable();
            $table->string('access_key', 50)->nullable();
            $table->string('vault_object_id', 26);
            $table->unsignedInteger('byte_size');
            $table->string('parse_status', 20)->default('OK');
            $table->text('parse_alert')->nullable();
            $table->timestamps();

            $table->unique(['office_id', 'sha256']);
            $table->index(['office_id', 'access_key']);
        });

        Schema::create('document_interests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dfe_document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('establishment_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('nsu');
            $table->string('environment', 40);
            $table->string('fiscal_role', 20)->nullable();
            $table->timestamps();

            $table->unique(['establishment_id', 'environment', 'nsu']);
            $table->unique(['dfe_document_id', 'establishment_id']);
        });

        Schema::create('nfse_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dfe_document_id')->constrained()->cascadeOnDelete();
            $table->string('access_key', 50);
            $table->string('issuer_cnpj', 14)->nullable();
            $table->string('taker_cnpj', 14)->nullable();
            $table->string('intermediary_cnpj', 14)->nullable();
            $table->string('fiscal_role', 20)->nullable();
            $table->string('competence', 7)->nullable();
            $table->timestampTz('issued_at')->nullable();
            $table->decimal('service_amount', 15, 2)->nullable();
            $table->string('status', 32)->default('UNKNOWN');
            $table->timestamps();

            $table->unique(['office_id', 'access_key']);
            $table->index(['office_id', 'competence']);
            $table->index(['office_id', 'issued_at']);
            $table->index(['office_id', 'issuer_cnpj']);
            $table->index(['office_id', 'taker_cnpj']);
        });

        Schema::create('nfse_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dfe_document_id')->constrained()->cascadeOnDelete();
            $table->string('access_key', 50);
            $table->string('event_type', 40)->nullable();
            $table->timestampTz('event_at')->nullable();
            $table->string('status', 32)->nullable();
            $table->timestamps();

            $table->index(['office_id', 'access_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nfse_events');
        Schema::dropIfExists('nfse_notes');
        Schema::dropIfExists('document_interests');
        Schema::dropIfExists('dfe_documents');
        Schema::dropIfExists('sync_runs');
        Schema::dropIfExists('sync_cursors');
    }
};
