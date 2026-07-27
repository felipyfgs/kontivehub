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
        Schema::create('tenant_distribution_cursors', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('tenant_fiscal_identity_id');
            $table->string('interested_root_cnpj', 8);
            $table->string('query_cnpj', 14);
            $table->string('environment', 40);
            $table->string('channel', 40)->default('NFE_AUTXML_DISTDFE');
            $table->bigInteger('last_nsu')->default(0);
            $table->bigInteger('max_nsu_seen')->nullable();
            $table->string('status', 32)->default('IDLE');
            $table->string('last_cstat', 10)->nullable();
            $table->string('last_xmotivo')->nullable();
            $table->integer('consecutive_decode_failures')->default(0);
            $table->integer('attempts')->default(0);
            $table->timestampTz('activated_at')->nullable();
            $table->timestampTz('next_sync_at')->nullable();
            $table->timestampTz('last_success_at')->nullable();
            $table->timestampTz('last_heartbeat_at')->nullable();
            $table->timestampTz('locked_at')->nullable();
            $table->string('lock_owner')->nullable();
            $table->string('external_consumer_status', 40)->nullable();
            $table->text('last_error')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'channel', 'status']);
            $table->index(['tenant_id', 'channel']);
            $table->index(['status', 'next_sync_at']);
            $table->unique(['tenant_id', 'interested_root_cnpj', 'environment', 'channel'], 'tenant_distribution_cursors_tenant_id_interested_r_31ed154353');
            $table->foreign(['tenant_fiscal_identity_id'])->references(['id'])->on('tenant_fiscal_identities')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_distribution_cursors');
    }
};
