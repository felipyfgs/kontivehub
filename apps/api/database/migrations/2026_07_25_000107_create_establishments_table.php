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
        Schema::create('establishments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('client_id');
            $table->string('cnpj', 14);
            $table->string('trade_name')->nullable();
            $table->boolean('is_headquarters')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->string('registration_status', 16)->default('UNKNOWN');
            $table->date('registration_status_at')->nullable();
            $table->string('registration_status_reason')->nullable();
            $table->date('activity_started_at')->nullable();
            $table->string('main_cnae_code', 16)->nullable();
            $table->string('main_cnae_name')->nullable();
            $table->string('address_postal_code', 16)->nullable();
            $table->string('address_street_type', 32)->nullable();
            $table->string('address_street')->nullable();
            $table->string('address_number', 32)->nullable();
            $table->string('address_complement')->nullable();
            $table->string('address_district')->nullable();
            $table->string('address_city')->nullable();
            $table->string('address_city_ibge_code', 16)->nullable();
            $table->string('address_state', 2)->nullable();
            $table->string('address_country', 64)->nullable();
            $table->string('public_email')->nullable();
            $table->string('public_phone', 32)->nullable();
            $table->boolean('capture_enabled')->default(true);
            $table->string('registration_source', 16)->default('MANUAL');
            $table->timestampTz('registration_refreshed_at')->nullable();
            $table->jsonb('secondary_cnaes')->nullable();
            $table->jsonb('state_registrations')->nullable();
            $table->jsonb('shareholders')->nullable();
            $table->string('public_phone_secondary', 32)->nullable();
            $table->string('public_fax', 32)->nullable();
            $table->string('special_situation')->nullable();
            $table->date('special_situation_at')->nullable();
            $table->boolean('simples_optant')->nullable();
            $table->boolean('mei_optant')->nullable();

            $table->index(['client_id', 'is_active']);
            $table->unique(['tenant_id', 'cnpj']);
            $table->unique(['tenant_id', 'id']);
            $table->foreign(['client_id'])->references(['id'])->on('clients')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['tenant_id', 'client_id'])->references(['tenant_id', 'id'])->on('clients')->onUpdate('no action')->onDelete('restrict');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('establishments');
    }
};
