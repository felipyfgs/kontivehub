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
        Schema::create('dctfweb_darf_documents', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('client_id');
            $table->bigInteger('declaration_id')->nullable();
            $table->bigInteger('competence_id')->nullable();
            $table->bigInteger('evidence_version_id')->nullable();
            $table->bigInteger('evidence_artifact_id')->nullable();
            $table->string('document_number', 80)->nullable();
            $table->decimal('amount', 15)->nullable();
            $table->timestampTz('due_at')->nullable();
            $table->timestampTz('issued_at')->nullable();
            $table->string('payment_status', 30)->default('UNKNOWN');
            $table->string('content_sha256', 64)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'client_id', 'declaration_id']);
            $table->unique(['tenant_id', 'content_sha256']);
            $table->foreign(['client_id'])->references(['id'])->on('clients')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['competence_id'])->references(['id'])->on('fiscal_competences')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['declaration_id'])->references(['id'])->on('dctfweb_declarations')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['evidence_artifact_id'])->references(['id'])->on('fiscal_evidence_artifacts')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['evidence_version_id'])->references(['id'])->on('dctfweb_evidence_versions')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dctfweb_darf_documents');
    }
};
