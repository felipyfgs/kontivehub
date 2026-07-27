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
        Schema::create('work_process_generation_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('batch_id');
            $table->bigInteger('client_id');
            $table->string('status', 32)->default('PREVIEWED');
            $table->boolean('is_blocked')->default(false);
            $table->jsonb('preview_payload');
            $table->jsonb('alerts')->nullable();
            $table->jsonb('conflicts')->nullable();
            $table->bigInteger('created_process_id')->nullable();
            $table->text('error_message')->nullable();
            $table->smallInteger('attempt_count')->default(0);
            $table->timestampsTz();

            $table->unique(['batch_id', 'client_id']);
            $table->index(['tenant_id', 'batch_id', 'status']);
            $table->foreign(['batch_id'])->references(['id'])->on('work_process_generation_batches')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['client_id'])->references(['id'])->on('clients')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['created_process_id'])->references(['id'])->on('work_processes')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_process_generation_items');
    }
};
