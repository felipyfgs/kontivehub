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
        Schema::create('communication_flow_inbox_bindings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('flow_id');
            $table->bigInteger('inbox_id');
            $table->bigInteger('published_version_id')->nullable();
            $table->boolean('enabled')->default(false);
            $table->integer('lock_version')->default(1);
            $table->timestampsTz();

            $table->unique(['flow_id', 'inbox_id']);
            $table->index(['tenant_id', 'inbox_id']);
            $table->foreign(['flow_id'])->references(['id'])->on('communication_flows')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['inbox_id'])->references(['id'])->on('communication_inboxes')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['published_version_id'])->references(['id'])->on('communication_flow_versions')->onUpdate('no action')->onDelete('set null');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('communication_flow_inbox_bindings');
    }
};
