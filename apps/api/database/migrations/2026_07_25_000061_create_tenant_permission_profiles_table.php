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
        Schema::create('tenant_permission_profiles', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->string('key', 64);
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->integer('authorization_version')->default(1);
            $table->timestampsTz();

            $table->unique(['id', 'tenant_id']);
            $table->index(['tenant_id', 'is_active']);
            $table->unique(['tenant_id', 'key']);
            $table->unique(['tenant_id', 'name']);
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('restrict');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_permission_profiles');
    }
};
