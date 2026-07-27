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
        Schema::create('cte_coverage_snapshots', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('client_id');
            $table->string('period', 7);
            $table->string('status', 40);
            $table->integer('documents_count')->default(0);
            $table->integer('original_count')->default(0);
            $table->integer('autxml_redacted_count')->default(0);
            $table->integer('pending_import_count')->default(0);
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('computed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'client_id', 'period']);
            $table->index(['tenant_id', 'status']);
            $table->foreign(['client_id'])->references(['id'])->on('clients')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cte_coverage_snapshots');
    }
};
