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
        Schema::create('cte_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('dfe_document_id');
            $table->bigInteger('cte_document_id')->nullable()->index();
            $table->string('access_key', 50);
            $table->string('event_type', 20)->nullable();
            $table->smallInteger('sequence')->nullable();
            $table->string('protocol_number', 30)->nullable();
            $table->string('cstat', 10)->nullable();
            $table->timestampTz('event_at')->nullable();
            $table->string('status', 32)->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'access_key']);
            $table->unique(['tenant_id', 'access_key', 'event_type', 'sequence']);
            $table->foreign(['cte_document_id'])->references(['id'])->on('cte_documents')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['dfe_document_id'])->references(['id'])->on('dfe_documents')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cte_events');
    }
};
