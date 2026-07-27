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
        Schema::create('sync_runs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('sync_cursor_id');
            $table->string('status', 32);
            $table->string('trigger', 20)->default('SCHEDULED');
            $table->bigInteger('triggered_by')->nullable();
            $table->integer('pages_processed')->default(0);
            $table->integer('documents_persisted')->default(0);
            $table->bigInteger('from_nsu')->default(0);
            $table->bigInteger('to_nsu')->default(0);
            $table->text('error_message')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['sync_cursor_id'])->references(['id'])->on('sync_cursors')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['triggered_by'])->references(['id'])->on('users')->onUpdate('no action')->onDelete('set null');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sync_runs');
    }
};
