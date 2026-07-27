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
        Schema::create('communication_automation_policies', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->string('module_key', 40);
            $table->string('submodule_key', 40);
            $table->bigInteger('inbox_id')->nullable();
            $table->boolean('is_enabled')->default(false);
            $table->smallInteger('send_day')->default(1);
            $table->time('send_time')->default('09:00:00');
            $table->string('timezone', 64)->default('America/Sao_Paulo');
            $table->string('recipient_mode', 24)->default('PRIMARY');
            $table->string('template_key', 80);
            $table->string('template_version', 40)->default('1');
            $table->integer('lock_version')->default(1);
            $table->timestampsTz();

            $table->index(['is_enabled', 'send_day', 'send_time'], 'communication_automation_policies_is_enabled_send__bbfcea6d92');
            $table->unique(['tenant_id', 'module_key', 'submodule_key'], 'communication_automation_policies_tenant_id_module_a9d6014d6d');
            $table->foreign(['inbox_id'])->references(['id'])->on('communication_inboxes')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('communication_automation_policies');
    }
};
