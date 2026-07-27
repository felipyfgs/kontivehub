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
        Schema::create('fiscal_findings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('snapshot_id');
            $table->bigInteger('run_id');
            $table->bigInteger('client_id');
            $table->string('code', 80);
            $table->string('severity', 20)->default('INFO');
            $table->string('title');
            $table->text('detail')->nullable();
            $table->string('situation', 30)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampTz('resolved_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'client_id', 'is_active', 'severity']);
            $table->unique(['tenant_id', 'snapshot_id', 'code']);
            $table->index(['tenant_id', 'snapshot_id']);
            $table->foreign(['client_id'])->references(['id'])->on('clients')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['run_id'])->references(['id'])->on('fiscal_monitoring_runs')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['snapshot_id'])->references(['id'])->on('fiscal_snapshots')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fiscal_findings');
    }
};
