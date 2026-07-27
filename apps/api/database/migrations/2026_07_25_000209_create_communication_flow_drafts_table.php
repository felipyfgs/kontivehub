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
        Schema::create('communication_flow_drafts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id')->index();
            $table->bigInteger('flow_id')->unique();
            $table->text('graph_encrypted');
            $table->char('graph_digest', 64);
            $table->integer('lock_version')->default(1);
            $table->bigInteger('updated_by_membership_id')->nullable();
            $table->timestampsTz();
            $table->foreign(['flow_id'])->references(['id'])->on('communication_flows')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['updated_by_membership_id'])->references(['id'])->on('tenant_memberships')->onUpdate('no action')->onDelete('set null');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('communication_flow_drafts');
    }
};
