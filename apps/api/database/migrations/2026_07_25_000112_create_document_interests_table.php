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
        Schema::create('document_interests', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('dfe_document_id');
            $table->bigInteger('establishment_id');
            $table->bigInteger('nsu')->nullable();
            $table->string('environment', 40);
            $table->string('fiscal_role', 20)->nullable();
            $table->timestampsTz();
            $table->string('channel', 40)->default('NFSE_ADN');
            $table->string('direction', 10)->nullable();

            $table->unique(['dfe_document_id', 'establishment_id', 'fiscal_role', 'channel'], 'document_interests_dfe_document_id_establishment_i_4df7f5a709');
            $table->index(['establishment_id', 'channel', 'nsu']);
            $table->unique(['establishment_id', 'environment', 'channel', 'nsu', 'fiscal_role'], 'document_interests_establishment_id_environment_ch_08f4238389');
            $table->index(['tenant_id', 'direction']);
            $table->index(['tenant_id', 'fiscal_role']);
            $table->foreign(['dfe_document_id'])->references(['id'])->on('dfe_documents')->onUpdate('no action')->onDelete('restrict');
            $table->foreign(['establishment_id'])->references(['id'])->on('establishments')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_interests');
    }
};
