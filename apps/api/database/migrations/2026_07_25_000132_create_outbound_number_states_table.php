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
        Schema::create('outbound_number_states', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('outbound_capture_profile_id');
            $table->bigInteger('outbound_series_cursor_id');
            $table->integer('series');
            $table->bigInteger('nnf');
            $table->string('status', 40)->default('CONSULT_QUEUED');
            $table->string('candidate_access_key', 50)->nullable();
            $table->string('candidate_cnf', 12)->nullable();
            $table->string('discovered_access_key', 50)->nullable()->index();
            $table->string('last_cstat', 10)->nullable();
            $table->string('last_xmotivo', 500)->nullable();
            $table->string('protocol', 40)->nullable();
            $table->smallInteger('attempts')->default(0);
            $table->timestampTz('last_attempt_at')->nullable();
            $table->timestampTz('next_attempt_at')->nullable();
            $table->timestampTz('key_discovered_at')->nullable();
            $table->timestampTz('xml_captured_at')->nullable();
            $table->bigInteger('dfe_document_id')->nullable();
            $table->jsonb('sanitized_response')->nullable();
            $table->text('block_reason')->nullable();
            $table->timestampsTz();

            $table->unique(['outbound_capture_profile_id', 'series', 'nnf'], 'outbound_number_states_outbound_capture_profile_id_7a78439f2a');
            $table->index(['tenant_id', 'status']);
            $table->index(['outbound_series_cursor_id', 'status']);
            $table->foreign(['dfe_document_id'])->references(['id'])->on('dfe_documents')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['outbound_capture_profile_id'])->references(['id'])->on('outbound_capture_profiles')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['outbound_series_cursor_id'])->references(['id'])->on('outbound_series_cursors')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('outbound_number_states');
    }
};
