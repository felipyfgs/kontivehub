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
        Schema::create('tenant_memberships', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('user_id');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->bigInteger('work_department_id')->nullable();
            $table->enum('role', ['tenant_admin', 'tenant_user']);
            $table->bigInteger('permission_profile_id')->nullable()->index();
            $table->integer('authorization_version')->default(1);

            $table->index(['tenant_id', 'authorization_version']);
            $table->unique(['tenant_id', 'user_id']);
            $table->index(['tenant_id', 'role']);
            $table->index(['user_id', 'is_active']);
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['permission_profile_id'])->references(['id'])->on('tenant_permission_profiles')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['permission_profile_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('tenant_permission_profiles')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['user_id'])->references(['id'])->on('users')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['work_department_id'])->references(['id'])->on('work_departments')->onUpdate('no action')->onDelete('set null');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_memberships');
    }
};
