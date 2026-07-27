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
        Schema::create('communication_flows', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->string('name', 160);
            $table->string('status', 32)->default('paused');
            $table->integer('lock_version')->default(1);
            $table->bigInteger('created_by_membership_id')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'name']);
            $table->index(['tenant_id', 'status']);
            $table->foreign(['created_by_membership_id'])->references(['id'])->on('tenant_memberships')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('communication_flows');
    }
};
