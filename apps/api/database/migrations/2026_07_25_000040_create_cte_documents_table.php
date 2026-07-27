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
        Schema::create('cte_documents', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('dfe_document_id');
            $table->string('access_key', 50);
            $table->string('number', 20)->nullable();
            $table->string('series', 10)->nullable();
            $table->string('model', 5)->default('57');
            $table->string('issuer_cnpj', 14)->nullable();
            $table->string('issuer_name')->nullable();
            $table->string('taker_cnpj', 14)->nullable();
            $table->string('taker_name')->nullable();
            $table->string('sender_cnpj', 14)->nullable();
            $table->string('recipient_cnpj', 14)->nullable();
            $table->string('fiscal_role', 20)->nullable();
            $table->string('direction', 10)->default('IN');
            $table->timestampTz('issued_at')->nullable();
            $table->decimal('total_amount', 15)->nullable();
            $table->string('status', 32)->default('UNKNOWN');
            $table->string('official_status_code', 10)->nullable();
            $table->boolean('is_summary')->default(false);
            $table->string('schema_hint', 80)->nullable();
            $table->timestampsTz();
            $table->string('expeditor_cnpj', 14)->nullable();
            $table->string('expeditor_name')->nullable();
            $table->string('receiver_cnpj', 14)->nullable();
            $table->string('receiver_name')->nullable();
            $table->string('effective_taker_cnpj', 14)->nullable();
            $table->string('schema_version', 20)->nullable();
            $table->string('protocol_number', 30)->nullable();
            $table->string('coverage_status', 40)->nullable();

            $table->index(['tenant_id', 'coverage_status']);
            $table->index(['tenant_id', 'expeditor_cnpj']);
            $table->index(['tenant_id', 'direction']);
            $table->index(['tenant_id', 'issued_at']);
            $table->index(['tenant_id', 'issuer_cnpj']);
            $table->index(['tenant_id', 'taker_cnpj']);
            $table->unique(['tenant_id', 'access_key', 'is_summary']);
            $table->index(['tenant_id', 'receiver_cnpj']);
            $table->foreign(['dfe_document_id'])->references(['id'])->on('dfe_documents')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cte_documents');
    }
};
