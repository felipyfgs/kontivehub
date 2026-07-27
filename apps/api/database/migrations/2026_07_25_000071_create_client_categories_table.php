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
        Schema::create('client_categories', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->string('name', 80);
            $table->string('name_key', 80);
            $table->string('color', 20)->default('neutral');
            $table->boolean('is_active')->default(true);
            $table->bigInteger('created_by')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'is_active', 'name']);
            $table->unique(['tenant_id', 'name_key']);
            $table->foreign(['created_by'])->references(['id'])->on('users')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('client_categories');
    }
};
