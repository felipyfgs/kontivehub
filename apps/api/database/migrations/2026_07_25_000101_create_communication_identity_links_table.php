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
        Schema::create('communication_identity_links', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('identity_id');
            $table->bigInteger('client_id');
            $table->bigInteger('client_contact_id')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->boolean('receives_automatic')->default(true);
            $table->timestampsTz();

            $table->index(['tenant_id', 'client_id', 'receives_automatic'], 'communication_identity_links_tenant_id_client_id_r_984e84304b');
            $table->foreign(['client_contact_id'])->references(['id'])->on('client_contacts')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['client_id'])->references(['id'])->on('clients')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['identity_id'])->references(['id'])->on('communication_identities')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('communication_identity_links');
    }
};
