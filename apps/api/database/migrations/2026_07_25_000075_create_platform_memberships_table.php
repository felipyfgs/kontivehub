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
        Schema::create('platform_memberships', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('user_id');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->bigInteger('default_tenant_id')->nullable();
            $table->enum('role', ['platform_admin'])->index();

            $table->index(['user_id', 'is_active']);
            $table->foreign(['default_tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['user_id'])->references(['id'])->on('users')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('platform_memberships');
    }
};
