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
        Schema::create('tenant_distribution_runs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('tenant_distribution_cursor_id');
            $table->string('status', 32);
            $table->string('trigger', 20)->default('SCHEDULED');
            $table->bigInteger('triggered_by')->nullable();
            $table->bigInteger('from_nsu')->default(0);
            $table->bigInteger('to_nsu')->default(0);
            $table->integer('pages_processed')->default(0);
            $table->integer('documents_persisted')->default(0);
            $table->integer('documents_quarantined')->default(0);
            $table->integer('attempts')->default(0);
            $table->string('last_cstat', 10)->nullable();
            $table->string('error_code', 60)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_distribution_cursor_id', 'created_at'], 'tenant_distribution_runs_tenant_distribution_curso_1aea561731');
            $table->index(['tenant_id', 'created_at']);
            $table->foreign(['tenant_distribution_cursor_id'])->references(['id'])->on('tenant_distribution_cursors')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['triggered_by'])->references(['id'])->on('users')->onUpdate('no action')->onDelete('set null');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_distribution_runs');
    }
};
