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
        Schema::create('outbound_series_cursors', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('outbound_capture_profile_id')->index();
            $table->bigInteger('establishment_id');
            $table->string('environment', 40);
            $table->string('model', 5);
            $table->integer('series');
            $table->bigInteger('seed_nnf');
            $table->bigInteger('discovery_position');
            $table->bigInteger('highest_confirmed_nnf')->nullable();
            $table->string('status', 32)->default('SEED_READY');
            $table->string('tp_emis', 5)->default('1');
            $table->string('seed_access_key', 50)->nullable();
            $table->string('seed_vault_object_id', 26)->nullable();
            $table->string('seed_sha256', 64)->nullable();
            $table->timestampTz('seed_issued_at')->nullable();
            $table->timestampTz('next_run_at')->nullable();
            $table->timestampTz('last_run_at')->nullable();
            $table->timestampTz('locked_at')->nullable();
            $table->string('lock_owner', 100)->nullable();
            $table->text('last_error')->nullable();
            $table->string('last_cstat', 10)->nullable();
            $table->boolean('series_closed_for_mutation')->default(false);
            $table->timestampTz('series_closed_at')->nullable();
            $table->string('erp_coordination_ref')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'status', 'next_run_at']);
            $table->unique(['establishment_id', 'environment', 'model', 'series'], 'outbound_series_cursors_establishment_id_environme_6b3d432d7b');
            $table->foreign(['establishment_id'])->references(['id'])->on('establishments')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['outbound_capture_profile_id'])->references(['id'])->on('outbound_capture_profiles')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('outbound_series_cursors');
    }
};
