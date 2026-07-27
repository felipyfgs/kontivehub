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
        Schema::create('tenant_integration_tokens', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->string('name', 120);
            $table->string('token_prefix', 12)->index();
            $table->string('token_hash', 64);
            $table->string('scope', 40)->default('cte:ingest');
            $table->string('status', 32)->default('ACTIVE');
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->bigInteger('created_by')->nullable();
            $table->bigInteger('revoked_by')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'token_hash']);
            $table->index(['tenant_id', 'status']);
            $table->foreign(['created_by'])->references(['id'])->on('users')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['revoked_by'])->references(['id'])->on('users')->onUpdate('no action')->onDelete('set null');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_integration_tokens');
    }
};
