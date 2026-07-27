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
        Schema::create('tenant_credential_purpose_links', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('tenant_credential_id');
            $table->string('purpose', 40);
            $table->string('status', 32)->default('ACTIVE');
            $table->timestampTz('linked_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->bigInteger('linked_by_user_id')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_credential_id', 'purpose'], 'tenant_credential_purpose_links_tenant_credential__68e404adfc');
            $table->index(['tenant_id', 'purpose', 'status']);
            $table->foreign(['linked_by_user_id'])->references(['id'])->on('users')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['tenant_credential_id'])->references(['id'])->on('tenant_credentials')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_credential_purpose_links');
    }
};
