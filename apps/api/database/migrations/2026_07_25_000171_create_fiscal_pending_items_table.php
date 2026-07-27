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
        Schema::create('fiscal_pending_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('client_id');
            $table->bigInteger('snapshot_id')->nullable();
            $table->bigInteger('run_id')->nullable();
            $table->bigInteger('fiscal_category_id')->nullable();
            $table->bigInteger('competence_id')->nullable();
            $table->bigInteger('finding_id')->nullable();
            $table->string('code', 80);
            $table->string('title');
            $table->text('detail')->nullable();
            $table->string('severity', 20)->default('MEDIUM');
            $table->string('status', 32)->default('OPEN');
            $table->string('situation', 30)->default('PENDING');
            $table->timestampTz('due_at')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->string('logical_key', 160);
            $table->string('open_dedupe_key', 160)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'client_id', 'open_dedupe_key']);
            $table->index(['tenant_id', 'client_id', 'status']);
            $table->index(['tenant_id', 'logical_key']);
            $table->index(['tenant_id', 'status', 'due_at']);
            $table->foreign(['client_id'])->references(['id'])->on('clients')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['competence_id'])->references(['id'])->on('fiscal_competences')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['finding_id'])->references(['id'])->on('fiscal_findings')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['fiscal_category_id'])->references(['id'])->on('fiscal_categories')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['run_id'])->references(['id'])->on('fiscal_monitoring_runs')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['snapshot_id'])->references(['id'])->on('fiscal_snapshots')->onUpdate('no action')->onDelete('set null');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fiscal_pending_items');
    }
};
