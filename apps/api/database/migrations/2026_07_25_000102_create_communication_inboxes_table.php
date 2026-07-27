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
        Schema::create('communication_inboxes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->string('name', 120);
            $table->string('session_id', 128)->unique();
            $table->text('address_encrypted')->nullable();
            $table->char('address_hash', 64)->nullable();
            $table->string('address_masked', 40)->nullable();
            $table->string('status', 32)->default('DISCONNECTED');
            $table->boolean('is_enabled')->default(false);
            $table->boolean('is_default')->default(false);
            $table->bigInteger('work_department_id')->nullable();
            $table->integer('lock_version')->default(1);
            $table->jsonb('settings')->nullable();
            $table->timestampTz('connected_at')->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'address_hash']);
            $table->unique(['tenant_id', 'name']);
            $table->index(['tenant_id', 'status', 'is_enabled']);
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['work_department_id'])->references(['id'])->on('work_departments')->onUpdate('no action')->onDelete('set null');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('communication_inboxes');
    }
};
