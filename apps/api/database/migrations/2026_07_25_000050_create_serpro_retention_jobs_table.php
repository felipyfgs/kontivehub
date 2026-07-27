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
        Schema::create('serpro_retention_jobs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id')->nullable();
            $table->string('category', 40);
            $table->string('status', 32)->default('PENDING');
            $table->string('trigger', 40)->default('OFFBOARDING');
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('eligible_purge_at')->nullable();
            $table->timestampTz('purged_at')->nullable();
            $table->bigInteger('requested_by_user_id')->nullable();
            $table->string('reason', 500)->nullable();
            $table->jsonb('summary')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'category', 'status']);
            $table->index(['status', 'eligible_purge_at']);
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('set null');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('serpro_retention_jobs');
    }
};
