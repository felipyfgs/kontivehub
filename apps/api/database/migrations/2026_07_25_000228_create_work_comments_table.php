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
        Schema::create('work_comments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('work_process_id')->nullable();
            $table->bigInteger('work_task_id')->nullable();
            $table->bigInteger('author_membership_id');
            $table->text('body');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['tenant_id', 'work_process_id', 'created_at']);
            $table->index(['tenant_id', 'work_task_id', 'created_at']);
            $table->foreign(['author_membership_id'])->references(['id'])->on('tenant_memberships')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['work_process_id'])->references(['id'])->on('work_processes')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['work_task_id'])->references(['id'])->on('work_tasks')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_comments');
    }
};
