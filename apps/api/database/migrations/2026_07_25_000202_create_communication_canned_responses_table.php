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
        Schema::create('communication_canned_responses', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->string('title', 120);
            $table->string('shortcut', 80);
            $table->text('body_encrypted');
            $table->boolean('is_active')->default(true);
            $table->bigInteger('created_by_membership_id')->nullable();
            $table->timestampsTz();
            $table->integer('lock_version')->default(1);

            $table->unique(['tenant_id', 'shortcut']);
            $table->foreign(['created_by_membership_id'])->references(['id'])->on('tenant_memberships')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('communication_canned_responses');
    }
};
