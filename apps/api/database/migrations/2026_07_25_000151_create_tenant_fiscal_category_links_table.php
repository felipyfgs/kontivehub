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
        Schema::create('tenant_fiscal_category_links', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('client_id');
            $table->bigInteger('fiscal_category_id');
            $table->string('status', 32)->default('ACTIVE');
            $table->string('coverage', 30)->default('UNKNOWN');
            $table->timestampTz('activated_at')->nullable();
            $table->timestampTz('deactivated_at')->nullable();
            $table->text('notes')->nullable();
            $table->bigInteger('created_by')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'client_id', 'fiscal_category_id'], 'tenant_fiscal_category_links_tenant_id_client_id_f_a4a9408d4d');
            $table->index(['tenant_id', 'client_id']);
            $table->index(['tenant_id', 'status', 'fiscal_category_id'], 'tenant_fiscal_category_links_tenant_id_status_fisc_f6e1fd1d3d');
            $table->foreign(['client_id'])->references(['id'])->on('clients')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['created_by'])->references(['id'])->on('users')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['fiscal_category_id'])->references(['id'])->on('fiscal_categories')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_fiscal_category_links');
    }
};
