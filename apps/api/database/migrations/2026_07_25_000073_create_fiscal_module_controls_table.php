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
        Schema::create('fiscal_module_controls', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('module_key', 48);
            $table->string('scope', 12);
            $table->bigInteger('tenant_id')->nullable();
            $table->boolean('restricted')->default(true);
            $table->string('reason', 500);
            $table->bigInteger('updated_by_user_id');
            $table->timestampTz('restricted_at')->nullable();
            $table->bigInteger('blocked_jobs_count')->default(0);
            $table->string('control_key', 96)->unique();
            $table->timestampsTz();

            $table->index(['module_key', 'scope']);
            $table->index(['tenant_id', 'module_key']);
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign(['updated_by_user_id'])->references(['id'])->on('users')->onUpdate('cascade')->onDelete('restrict');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fiscal_module_controls');
    }
};
