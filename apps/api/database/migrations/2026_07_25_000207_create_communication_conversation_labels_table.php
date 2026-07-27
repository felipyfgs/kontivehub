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
        Schema::create('communication_conversation_labels', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('conversation_id');
            $table->bigInteger('label_id');
            $table->bigInteger('assigned_by_membership_id')->nullable();
            $table->timestampsTz();

            $table->unique(['conversation_id', 'label_id'], 'communication_conversation_labels_conversation_id__4fbdd0876e');
            $table->foreign(['assigned_by_membership_id'], 'communication_conversation_labels_assigned_by_memb_a650ad6ce9')->references(['id'])->on('tenant_memberships')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['conversation_id'])->references(['id'])->on('communication_conversations')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['label_id'])->references(['id'])->on('communication_labels')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('communication_conversation_labels');
    }
};
