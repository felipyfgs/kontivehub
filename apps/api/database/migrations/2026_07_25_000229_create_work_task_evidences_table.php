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
        Schema::create('work_task_evidences', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('work_task_id');
            $table->string('original_filename');
            $table->string('mime_type', 100);
            $table->bigInteger('byte_size');
            $table->string('sha256', 64);
            $table->string('vault_object_id', 26);
            $table->bigInteger('uploaded_by_membership_id');
            $table->text('removal_reason')->nullable();
            $table->timestampTz('removed_at')->nullable();
            $table->bigInteger('removed_by_membership_id')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'work_task_id']);
            $table->index(['tenant_id', 'sha256']);
            $table->unique(['tenant_id', 'vault_object_id']);
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['work_task_id'])->references(['id'])->on('work_tasks')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['removed_by_membership_id'])->references(['id'])->on('tenant_memberships')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['uploaded_by_membership_id'])->references(['id'])->on('tenant_memberships')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_task_evidences');
    }
};
