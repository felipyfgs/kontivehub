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
        Schema::create('tenant_creation_idempotency', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('idempotency_key', 128)->unique();
            $table->bigInteger('tenant_id');
            $table->string('request_hash', 64);
            $table->timestampTz('created_at')->useCurrent();
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_creation_idempotency');
    }
};
