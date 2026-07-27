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
        Schema::create('communication_flow_versions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('flow_id');
            $table->integer('version');
            $table->text('graph_encrypted');
            $table->char('graph_digest', 64);
            $table->timestampTz('published_at');
            $table->bigInteger('published_by_membership_id')->nullable();
            $table->timestampsTz();

            $table->unique(['flow_id', 'version']);
            $table->index(['tenant_id', 'flow_id']);
            $table->foreign(['flow_id'])->references(['id'])->on('communication_flows')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['published_by_membership_id'])->references(['id'])->on('tenant_memberships')->onUpdate('no action')->onDelete('set null');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('communication_flow_versions');
    }
};
