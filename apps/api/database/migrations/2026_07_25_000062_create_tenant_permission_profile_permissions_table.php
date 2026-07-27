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
        Schema::create('tenant_permission_profile_permissions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('permission_profile_id');
            $table->string('permission_key', 80)->index();
            $table->timestampsTz();

            $table->unique(['permission_profile_id', 'permission_key'], 'tenant_permission_profile_permissions_permission_p_99260ecbab');
            $table->foreign(['permission_profile_id'], 'tenant_permission_profile_permissions_permission_p_cdf443cd56')->references(['id'])->on('tenant_permission_profiles')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_permission_profile_permissions');
    }
};
