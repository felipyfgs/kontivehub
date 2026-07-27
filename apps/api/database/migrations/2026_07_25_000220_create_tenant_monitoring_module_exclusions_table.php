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
        Schema::create('tenant_monitoring_module_exclusions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('client_id');
            $table->string('module_key', 64);
            $table->string('submodule', 64)->default('');
            $table->bigInteger('excluded_by')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'client_id', 'module_key', 'submodule'], 'tenant_monitoring_module_exclusions_tenant_id_clie_6346b2d589');
            $table->index(['tenant_id', 'module_key', 'submodule'], 'tenant_monitoring_module_exclusions_tenant_id_modu_68f31a599b');
            $table->foreign(['client_id'])->references(['id'])->on('clients')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['excluded_by'])->references(['id'])->on('users')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_monitoring_module_exclusions');
    }
};
