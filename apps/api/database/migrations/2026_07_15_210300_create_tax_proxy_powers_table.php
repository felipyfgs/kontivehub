<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Procurações/poderes por contribuinte e serviço — tenant-scoped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_proxy_powers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('office_serpro_authorization_id')
                ->nullable()
                ->constrained('office_serpro_authorizations')
                ->nullOnDelete();
            $table->string('author_identity', 14);
            $table->string('contributor_cnpj', 14);
            $table->string('system_code', 80);
            $table->string('service_code', 120)->nullable();
            $table->string('power_code', 120);
            $table->string('source', 40);
            $table->string('status', 32);
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_to')->nullable();
            $table->string('evidence_ref', 120)->nullable();
            $table->string('evidence_sha256', 64)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->string('last_check_result', 80)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['office_id', 'client_id', 'status']);
            $table->index(['office_id', 'power_code', 'status']);
            $table->index(['office_id', 'contributor_cnpj']);
            $table->unique(
                ['office_id', 'client_id', 'power_code', 'author_identity', 'source'],
                'tax_proxy_powers_unique_power',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_proxy_powers');
    }
};
