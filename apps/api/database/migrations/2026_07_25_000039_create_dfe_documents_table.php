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
        Schema::create('dfe_documents', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->string('sha256', 64);
            $table->string('document_type', 20);
            $table->string('schema_version')->nullable();
            $table->string('access_key', 50)->nullable();
            $table->string('vault_object_id', 26);
            $table->integer('byte_size');
            $table->string('parse_status', 20)->default('OK');
            $table->text('parse_alert')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'access_key']);
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'sha256']);
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('restrict');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dfe_documents');
    }
};
