<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('esocial_bx_access_ledgers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('employer_hash', 64);
            $table->string('environment', 20);
            $table->string('operation', 40);
            $table->date('access_date');
            $table->string('status', 24)->default('RESERVED');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('official_code', 8)->nullable();
            $table->boolean('retryable')->default(false);
            $table->string('correlation_id', 64)->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestamps();

            $table->index(
                ['employer_hash', 'environment', 'access_date'],
                'esocial_bx_employer_env_date_idx',
            );
            $table->index(['office_id', 'client_id', 'created_at'], 'esocial_bx_tenant_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('esocial_bx_access_ledgers');
    }
};
