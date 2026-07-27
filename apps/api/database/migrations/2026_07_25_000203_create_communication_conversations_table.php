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
        Schema::create('communication_conversations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('inbox_id');
            $table->bigInteger('identity_id');
            $table->string('status', 32)->default('OPEN');
            $table->bigInteger('work_department_id')->nullable();
            $table->bigInteger('assignee_membership_id')->nullable();
            $table->smallInteger('priority')->default(0);
            $table->timestampTz('snoozed_until')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampTz('last_message_at')->nullable();
            $table->integer('lock_version')->default(1);
            $table->timestampTz('purged_at')->nullable();
            $table->char('tombstone_digest', 64)->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'assignee_membership_id', 'status'], 'communication_conversations_tenant_id_assignee_mem_fce0a0cc1d');
            $table->index(['tenant_id', 'work_department_id', 'status'], 'communication_conversations_tenant_id_work_departm_fc8880c1d8');
            $table->index(['tenant_id', 'inbox_id', 'status', 'last_message_at'], 'communication_conversations_tenant_id_inbox_id_sta_041d65e05f');
            $table->foreign(['assignee_membership_id'])->references(['id'])->on('tenant_memberships')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['identity_id'])->references(['id'])->on('communication_identities')->onUpdate('no action')->onDelete('restrict');
            $table->foreign(['inbox_id'])->references(['id'])->on('communication_inboxes')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['work_department_id'])->references(['id'])->on('work_departments')->onUpdate('no action')->onDelete('set null');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('communication_conversations');
    }
};
