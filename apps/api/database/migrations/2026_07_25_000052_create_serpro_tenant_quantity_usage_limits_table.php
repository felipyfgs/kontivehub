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
        Schema::create('serpro_tenant_quantity_usage_limits', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->string('environment', 20);
            $table->bigInteger('limit_quantity')->nullable();
            $table->boolean('is_active')->default(true);
            $table->bigInteger('updated_by_user_id')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->index(['environment', 'is_active']);
            $table->unique(['tenant_id', 'environment'], 'serpro_tenant_quantity_usage_limits_tenant_id_envi_068219921d');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('serpro_tenant_quantity_usage_limits');
    }
};
