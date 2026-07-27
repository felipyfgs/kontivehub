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
        Schema::create('sync_cursors', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('establishment_id');
            $table->string('environment', 40);
            $table->bigInteger('last_nsu')->default(0);
            $table->enum('status', ['IDLE', 'RUNNING', 'WAITING', 'ERROR', 'BLOCKED'])->default('IDLE');
            $table->integer('consecutive_decode_failures')->default(0);
            $table->integer('attempts')->default(0);
            $table->timestampTz('next_sync_at')->nullable();
            $table->timestampTz('last_success_at')->nullable();
            $table->timestampTz('locked_at')->nullable();
            $table->string('lock_owner')->nullable();
            $table->text('last_error')->nullable();
            $table->timestampsTz();

            $table->unique(['establishment_id', 'environment']);
            $table->unique(['tenant_id', 'id']);
            $table->index(['status', 'next_sync_at']);
            $table->foreign(['establishment_id'])->references(['id'])->on('establishments')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sync_cursors');
    }
};
