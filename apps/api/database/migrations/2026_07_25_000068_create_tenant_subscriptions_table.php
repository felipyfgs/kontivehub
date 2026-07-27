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
        Schema::create('tenant_subscriptions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id')->unique();
            $table->string('plan', 40);
            $table->string('status', 32)->index();
            $table->timestampTz('trial_ends_at')->nullable();
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->timestampTz('current_period_starts_at')->nullable();
            $table->timestampTz('current_period_ends_at')->nullable();
            $table->integer('monthly_api_quota')->nullable();
            $table->integer('max_clients')->nullable();
            $table->integer('max_users')->nullable();
            $table->jsonb('limits')->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();
            $table->smallInteger('commercial_monitor_units')->nullable();
            $table->integer('negotiated_client_limit')->nullable();
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_subscriptions');
    }
};
