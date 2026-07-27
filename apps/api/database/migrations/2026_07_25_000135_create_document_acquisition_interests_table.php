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
        Schema::create('document_acquisition_interests', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('document_acquisition_id');
            $table->bigInteger('document_interest_id');
            $table->timestampsTz();

            $table->unique(['document_acquisition_id', 'document_interest_id'], 'document_acquisition_interests_document_acquisitio_a5378f99fd');
            $table->index(['tenant_id', 'document_interest_id'], 'document_acquisition_interests_tenant_id_document__d4fb38eef1');
            $table->foreign(['document_acquisition_id'])->references(['id'])->on('document_acquisitions')->onUpdate('no action')->onDelete('restrict');
            $table->foreign(['document_interest_id'])->references(['id'])->on('document_interests')->onUpdate('no action')->onDelete('restrict');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('restrict');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_acquisition_interests');
    }
};
