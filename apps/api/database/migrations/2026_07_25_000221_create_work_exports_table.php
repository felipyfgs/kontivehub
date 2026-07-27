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
        Schema::create('work_exports', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('requested_by_membership_id');
            $table->string('status', 32)->default('PENDING');
            $table->jsonb('filters_snapshot');
            $table->string('storage_path')->nullable();
            $table->bigInteger('byte_size')->nullable();
            $table->integer('row_count')->default(0);
            $table->text('error_message')->nullable();
            $table->timestampTz('expires_at')->nullable()->index();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'requested_by_membership_id', 'status']);
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['requested_by_membership_id'])->references(['id'])->on('tenant_memberships')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_exports');
    }
};
