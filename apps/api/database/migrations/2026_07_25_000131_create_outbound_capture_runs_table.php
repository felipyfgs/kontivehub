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
        Schema::create('outbound_capture_runs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('outbound_capture_profile_id');
            $table->bigInteger('outbound_series_cursor_id')->nullable();
            $table->string('run_type', 40)->default('SEQUENCE_QUERY');
            $table->string('status', 32)->default('QUEUED');
            $table->bigInteger('nnf_start')->nullable();
            $table->bigInteger('nnf_end')->nullable();
            $table->integer('numbers_consulted')->default(0);
            $table->integer('keys_discovered')->default(0);
            $table->integer('xml_persisted')->default(0);
            $table->integer('gaps_open')->default(0);
            $table->integer('attempts_total')->default(0);
            $table->string('result_summary')->nullable();
            $table->text('last_error')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->string('triggered_by', 40)->default('scheduler');
            $table->bigInteger('user_id')->nullable();
            $table->jsonb('metrics')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'status', 'created_at']);
            $table->index(['outbound_series_cursor_id', 'created_at'], 'outbound_capture_runs_outbound_series_cursor_id_cr_92b6fe6502');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['outbound_capture_profile_id'])->references(['id'])->on('outbound_capture_profiles')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['outbound_series_cursor_id'])->references(['id'])->on('outbound_series_cursors')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['user_id'])->references(['id'])->on('users')->onUpdate('no action')->onDelete('set null');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('outbound_capture_runs');
    }
};
