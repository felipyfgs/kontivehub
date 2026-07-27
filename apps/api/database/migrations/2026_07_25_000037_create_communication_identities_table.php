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
        Schema::create('communication_identities', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('contact_id');
            $table->string('channel', 20)->default('WHATSAPP');
            $table->text('address_encrypted')->nullable();
            $table->char('address_hash', 64);
            $table->string('address_masked', 40);
            $table->boolean('is_active')->default(true);
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampTz('purged_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'contact_id', 'is_active']);
            $table->unique(['tenant_id', 'channel', 'address_hash']);
            $table->foreign(['contact_id'])->references(['id'])->on('communication_contacts')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('communication_identities');
    }
};
