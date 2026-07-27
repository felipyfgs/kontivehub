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
        Schema::create('tenant_institutional_profiles', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id')->unique();
            $table->string('cnpj', 14)->nullable();
            $table->string('legal_name')->nullable();
            $table->string('institutional_email')->nullable();
            $table->string('institutional_phone', 40)->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'cnpj']);
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_institutional_profiles');
    }
};
