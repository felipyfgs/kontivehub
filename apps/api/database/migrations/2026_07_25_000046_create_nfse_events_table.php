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
        Schema::create('nfse_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('dfe_document_id');
            $table->string('access_key', 50);
            $table->string('event_type', 40)->nullable();
            $table->timestampTz('event_at')->nullable();
            $table->string('status', 32)->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'access_key']);
            $table->foreign(['dfe_document_id'])->references(['id'])->on('dfe_documents')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nfse_events');
    }
};
