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
        Schema::create('nfe_documents', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('dfe_document_id');
            $table->string('access_key', 50);
            $table->string('number', 20)->nullable();
            $table->string('series', 10)->nullable();
            $table->string('model', 5)->default('55');
            $table->string('issuer_cnpj', 14)->nullable();
            $table->string('issuer_name')->nullable();
            $table->string('recipient_cnpj', 14)->nullable();
            $table->string('recipient_name')->nullable();
            $table->string('fiscal_role', 20)->nullable();
            $table->timestampTz('issued_at')->nullable();
            $table->decimal('total_amount', 15)->nullable();
            $table->string('status', 32)->default('UNKNOWN');
            $table->string('official_status_code', 10)->nullable();
            $table->boolean('is_summary')->default(false);
            $table->string('manifestation_status', 40)->nullable();
            $table->string('schema_hint', 80)->nullable();
            $table->timestampsTz();
            $table->string('direction', 10)->default('UNKNOWN');
            $table->string('purpose', 20)->default('COMMERCIAL');
            $table->string('acquisition_source', 40)->nullable();
            $table->string('parser_version', 40)->nullable();

            $table->index(['tenant_id', 'acquisition_source']);
            $table->index(['tenant_id', 'direction']);
            $table->index(['tenant_id', 'issued_at']);
            $table->index(['tenant_id', 'issuer_cnpj']);
            $table->index(['tenant_id', 'manifestation_status']);
            $table->index(['tenant_id', 'purpose']);
            $table->index(['tenant_id', 'recipient_cnpj']);
            $table->index(['tenant_id', 'status']);
            $table->unique(['tenant_id', 'access_key', 'is_summary']);
            $table->foreign(['dfe_document_id'])->references(['id'])->on('dfe_documents')->onUpdate('no action')->onDelete('restrict');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nfe_documents');
    }
};
