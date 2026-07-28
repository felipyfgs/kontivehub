<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_credentials', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->string('status', 32);
            $table->string('subject_name');
            $table->string('holder_cnpj', 14);
            $table->string('fingerprint_sha256', 64);
            $table->timestampTz('valid_from');
            $table->timestampTz('valid_to');
            $table->string('vault_object_id', 26);
            $table->timestampTz('activated_at')->nullable();
            $table->timestampTz('superseded_at')->nullable();
            $table->timestampTz('last_used_at')->nullable();
            $table->boolean('expires_alert_30')->default(false);
            $table->boolean('expires_alert_7')->default(false);
            $table->boolean('expires_alert_1')->default(false);
            $table->timestampsTz();

            $table->index(['tenant_id', 'valid_to']);
            $table->index(['tenant_id', 'status']);
            $table->unique(
                ['tenant_id', 'fingerprint_sha256', 'status'],
                'tenant_credentials_fingerprint_status_unique',
            );
            $table->foreign(['tenant_id'])
                ->references(['id'])
                ->on('tenants')
                ->onUpdate('no action')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_credentials');
    }
};
