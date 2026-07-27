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
        Schema::create('tenant_serpro_authorization_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('tenant_serpro_authorization_id');
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40);
            $table->string('event', 80);
            $table->string('message', 500)->nullable();
            $table->bigInteger('actor_user_id')->nullable();
            $table->jsonb('context')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['tenant_id', 'tenant_serpro_authorization_id'], 'tenant_serpro_authorization_events_tenant_id_tenan_3e8c8d8e7f');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['tenant_serpro_authorization_id'], 'tenant_serpro_authorization_events_tenant_serpro_a_5abab62bfe')->references(['id'])->on('tenant_serpro_authorizations')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_serpro_authorization_events');
    }
};
