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
        Schema::create('client_communication_preferences', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('client_id');
            $table->string('module_key', 40)->default('simples_mei');
            $table->string('submodule_key', 40)->default('pgdasd');
            $table->boolean('automatic_requested')->default(false);
            $table->boolean('email_enabled')->default(false);
            $table->boolean('whatsapp_enabled')->default(false);
            $table->integer('lock_version')->default(1);
            $table->bigInteger('updated_by_user_id')->nullable();
            $table->timestampsTz();
            $table->string('recipient_mode', 24)->default('PRIMARY');

            $table->unique(['tenant_id', 'client_id', 'module_key', 'submodule_key'], 'client_communication_preferences_tenant_id_client__8e94e628f0');
            $table->index(['tenant_id', 'module_key', 'submodule_key', 'automatic_requested'], 'client_communication_preferences_tenant_id_module__a106638193');
            $table->foreign(['client_id'])->references(['id'])->on('clients')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['updated_by_user_id'])->references(['id'])->on('users')->onUpdate('no action')->onDelete('set null');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('client_communication_preferences');
    }
};
