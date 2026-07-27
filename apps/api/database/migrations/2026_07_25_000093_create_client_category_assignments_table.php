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
        Schema::create('client_category_assignments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('client_id');
            $table->bigInteger('client_category_id');
            $table->bigInteger('assigned_by')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'client_category_id']);
            $table->unique(['tenant_id', 'client_id', 'client_category_id'], 'client_category_assignments_tenant_id_client_id_cl_1bc5b0e01c');
            $table->index(['tenant_id', 'client_id']);
            $table->foreign(['assigned_by'])->references(['id'])->on('users')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['client_category_id'])->references(['id'])->on('client_categories')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['client_id'])->references(['id'])->on('clients')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('client_category_assignments');
    }
};
