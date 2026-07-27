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
        Schema::create('vault_object_journal_entries', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('object_id', 26)->unique();
            $table->string('purpose', 60);
            $table->integer('crypto_key_version')->default(1);
            $table->string('rewrap_status', 20)->default('CURRENT');
            $table->string('retention_class', 40)->nullable();
            $table->timestampTz('retain_until')->nullable();
            $table->timestampTz('orphaned_at')->nullable();
            $table->softDeletesTz();
            $table->string('content_sha256', 64)->nullable();
            $table->bigInteger('tenant_id')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->index(['orphaned_at', 'deleted_at']);
            $table->index(['purpose', 'rewrap_status']);
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('set null');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vault_object_journal_entries');
    }
};
