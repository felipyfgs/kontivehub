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
        Schema::create('nfse_documents', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('dfe_document_id');
            $table->string('access_key', 50);
            $table->string('issuer_cnpj', 14)->nullable();
            $table->string('taker_cnpj', 14)->nullable();
            $table->string('intermediary_cnpj', 14)->nullable();
            $table->string('fiscal_role', 20)->nullable();
            $table->string('competence', 7)->nullable();
            $table->timestampTz('issued_at')->nullable();
            $table->decimal('service_amount', 15)->nullable();
            $table->string('status', 32)->default('UNKNOWN');
            $table->timestampsTz();
            $table->string('number', 20)->nullable();
            $table->string('issuer_name')->nullable();
            $table->string('taker_name')->nullable();
            $table->string('intermediary_name')->nullable();
            $table->string('issue_location', 120)->nullable();
            $table->string('service_location', 120)->nullable();
            $table->string('official_status_code', 10)->nullable();
            $table->string('direction', 10)->default('UNKNOWN');
            $table->string('parser_version', 40)->nullable();

            $table->unique(['tenant_id', 'access_key']);
            $table->index(['tenant_id', 'competence']);
            $table->index(['tenant_id', 'direction']);
            $table->index(['tenant_id', 'issued_at']);
            $table->index(['tenant_id', 'issuer_cnpj']);
            $table->index(['tenant_id', 'number']);
            $table->index(['tenant_id', 'taker_cnpj']);
            $table->foreign(['dfe_document_id'])->references(['id'])->on('dfe_documents')->onUpdate('no action')->onDelete('restrict');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nfse_documents');
    }
};
