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
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->smallInteger('id')->primary();
            $table->string('organization_name');
            $table->timestampTz('onboarding_completed_at')->nullable();
            $table->bigInteger('onboarded_by_user_id')->nullable();
            $table->timestampsTz();
            $table->bigInteger('primary_tenant_id')->nullable();
            $table->foreign(['onboarded_by_user_id'])->references(['id'])->on('users')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['primary_tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('restrict');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
    }
};
