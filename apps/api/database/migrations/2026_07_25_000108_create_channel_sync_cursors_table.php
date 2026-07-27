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
        Schema::create('channel_sync_cursors', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('establishment_id');
            $table->string('environment', 40);
            $table->string('source', 20);
            $table->string('channel', 40);
            $table->bigInteger('last_nsu')->default(0);
            $table->bigInteger('max_nsu_seen')->nullable();
            $table->string('status', 32)->default('IDLE');
            $table->string('last_cstat', 10)->nullable();
            $table->string('last_xmotivo')->nullable();
            $table->integer('consecutive_decode_failures')->default(0);
            $table->integer('attempts')->default(0);
            $table->timestampTz('next_sync_at')->nullable();
            $table->timestampTz('last_success_at')->nullable();
            $table->timestampTz('locked_at')->nullable();
            $table->string('lock_owner')->nullable();
            $table->text('last_error')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'channel']);
            $table->unique(['tenant_id', 'id']);
            $table->index(['status', 'next_sync_at']);
            $table->unique(['establishment_id', 'environment', 'source', 'channel'], 'channel_sync_cursors_establishment_id_environment__657d91411e');
            $table->foreign(['establishment_id'])->references(['id'])->on('establishments')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('channel_sync_cursors');
    }
};
