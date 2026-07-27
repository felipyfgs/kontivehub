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
        Schema::create('tenant_monitor_schedule_policies', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->string('monitor_key', 40);
            $table->smallInteger('day_of_month');
            $table->boolean('is_custom')->default(false);
            $table->string('timezone', 64)->nullable();
            $table->bigInteger('updated_by_user_id')->nullable();
            $table->timestampsTz();

            $table->index(['day_of_month', 'monitor_key']);
            $table->unique(['tenant_id', 'monitor_key']);
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['updated_by_user_id'])->references(['id'])->on('users')->onUpdate('no action')->onDelete('set null');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_monitor_schedule_policies');
    }
};
