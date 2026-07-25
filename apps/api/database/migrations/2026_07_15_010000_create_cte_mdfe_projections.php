<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Projeção CT-e (DistDFe próprio; NSU independente de NF-e).
 * MDF-e fica fora do escopo desta entrega.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cte_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dfe_document_id')->constrained()->cascadeOnDelete();
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
            $table->decimal('total_amount', 15, 2)->nullable();
            $table->string('status', 32)->default('UNKNOWN');
            $table->string('official_status_code', 10)->nullable();
            $table->boolean('is_summary')->default(false);
            $table->string('schema_hint', 80)->nullable();
            $table->timestamps();

            $table->unique(['office_id', 'access_key', 'is_summary'], 'cte_documents_office_key_summary');
            $table->index(['office_id', 'direction']);
            $table->index(['office_id', 'issued_at']);
            $table->index(['office_id', 'issuer_cnpj']);
            $table->index(['office_id', 'taker_cnpj']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cte_documents');
    }
};
