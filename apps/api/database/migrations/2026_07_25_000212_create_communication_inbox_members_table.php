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
        Schema::create('communication_inbox_members', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('inbox_id');
            $table->bigInteger('tenant_membership_id');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->index(['tenant_id', 'tenant_membership_id', 'is_active'], 'communication_inbox_members_tenant_id_tenant_membe_e7fafc104d');
            $table->unique(['inbox_id', 'tenant_membership_id'], 'communication_inbox_members_inbox_id_tenant_member_3cf15f8709');
            $table->foreign(['inbox_id'])->references(['id'])->on('communication_inboxes')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['tenant_membership_id'])->references(['id'])->on('tenant_memberships')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('communication_inbox_members');
    }
};
