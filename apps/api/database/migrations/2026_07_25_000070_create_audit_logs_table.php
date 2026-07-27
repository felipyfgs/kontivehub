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
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id')->nullable();
            $table->bigInteger('user_id')->nullable();
            $table->string('action', 80);
            $table->string('subject_type', 80)->nullable();
            $table->bigInteger('subject_id')->nullable();
            $table->string('result', 20)->default('SUCCESS');
            $table->jsonb('context')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->string('correlation_id', 64)->nullable()->index();
            $table->timestampTz('created_at')->useCurrent();
            $table->bigInteger('chain_seq')->nullable()->index();
            $table->string('prev_hash', 64)->nullable();
            $table->string('entry_hash', 64)->nullable()->index();

            $table->index(['tenant_id', 'action', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['user_id'])->references(['id'])->on('users')->onUpdate('no action')->onDelete('set null');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
