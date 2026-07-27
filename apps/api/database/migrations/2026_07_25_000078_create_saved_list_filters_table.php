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
        Schema::create('saved_list_filters', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('user_id');
            $table->string('surface', 128);
            $table->string('name', 120);
            $table->string('visibility', 16);
            $table->integer('schema_version')->default(1);
            $table->jsonb('payload');
            $table->timestampsTz();

            $table->index(['tenant_id', 'surface', 'user_id']);
            $table->index(['tenant_id', 'surface', 'visibility']);
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['user_id'])->references(['id'])->on('users')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('saved_list_filters');
    }
};
