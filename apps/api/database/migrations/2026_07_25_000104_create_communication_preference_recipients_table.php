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
        Schema::create('communication_preference_recipients', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('preference_id');
            $table->bigInteger('identity_id');
            $table->timestampsTz();

            $table->index(['tenant_id', 'identity_id']);
            $table->unique(['preference_id', 'identity_id'], 'communication_preference_recipients_preference_id__572fd939c5');
            $table->foreign(['identity_id'])->references(['id'])->on('communication_identities')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['preference_id'])->references(['id'])->on('client_communication_preferences')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('communication_preference_recipients');
    }
};
