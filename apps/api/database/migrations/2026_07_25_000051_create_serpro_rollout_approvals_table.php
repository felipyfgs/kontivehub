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
        Schema::create('serpro_rollout_approvals', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('subject_type', 40);
            $table->bigInteger('subject_id')->nullable();
            $table->string('action', 40);
            $table->string('environment', 20);
            $table->bigInteger('tenant_id')->nullable();
            $table->string('status', 32)->default('PENDING');
            $table->string('reason', 500)->nullable();
            $table->bigInteger('requested_by_user_id')->nullable();
            $table->bigInteger('first_approver_user_id')->nullable();
            $table->bigInteger('second_approver_user_id')->nullable();
            $table->timestampTz('first_approved_at')->nullable();
            $table->timestampTz('second_approved_at')->nullable();
            $table->timestampTz('executed_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->jsonb('context')->nullable();
            $table->timestampsTz();
            $table->string('approval_policy', 40)->default('DUAL_ROLE');
            $table->string('confirmation_phrase', 120)->nullable();
            $table->timestampTz('change_window_start')->nullable();
            $table->timestampTz('change_window_end')->nullable();

            $table->index(['status', 'environment']);
            $table->index(['subject_type', 'subject_id', 'action']);
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('set null');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('serpro_rollout_approvals');
    }
};
