<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_conversation_list_preferences', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('user_id');
            $table->string('status', 32)->default('OPEN');
            $table->string('sort_by', 32)->default('last_activity_desc');
            $table->timestampsTz();

            $table->unique(
                ['tenant_id', 'user_id'],
                'communication_list_prefs_tenant_user_uidx',
            );
            $table->index(
                ['tenant_id', 'user_id'],
                'communication_list_prefs_tenant_user_idx',
            );
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_conversation_list_preferences');
    }
};
