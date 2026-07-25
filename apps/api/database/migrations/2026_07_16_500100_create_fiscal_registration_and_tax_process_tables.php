<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Projeções tenant-scoped de Cadastro/Vínculos (PNR Contador) e e-Processo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_registration_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->constrained('offices')->restrictOnDelete();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->string('contributor_cnpj', 14);
            $table->string('link_key', 120);
            $table->string('status', 40)->default('UNKNOWN');
            $table->string('evidence_version', 64)->nullable();
            $table->string('operation_key', 120)->nullable();
            $table->string('source_provenance', 40)->default('UNVERIFIED');
            $table->boolean('is_simulated')->default(false);
            $table->json('summary_sanitized')->nullable();
            $table->timestampTz('observed_at')->nullable();
            $table->timestampTz('refreshed_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['office_id', 'client_id', 'link_key'],
                'fiscal_reg_links_office_client_key_uq',
            );
            $table->index(['office_id', 'contributor_cnpj'], 'fiscal_reg_links_office_cnpj_idx');
            $table->index(['office_id', 'status'], 'fiscal_reg_links_office_status_idx');
        });

        Schema::create('fiscal_tax_processes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->constrained('offices')->restrictOnDelete();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->string('contributor_cnpj', 14);
            $table->string('process_number', 80);
            $table->string('status', 40)->default('UNKNOWN');
            $table->string('evidence_version', 64)->nullable();
            $table->string('operation_key', 120)->nullable();
            $table->string('source_provenance', 40)->default('UNVERIFIED');
            $table->boolean('is_simulated')->default(false);
            $table->json('summary_sanitized')->nullable();
            $table->timestampTz('observed_at')->nullable();
            $table->timestampTz('refreshed_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['office_id', 'client_id', 'process_number'],
                'fiscal_tax_proc_office_client_num_uq',
            );
            $table->index(['office_id', 'contributor_cnpj'], 'fiscal_tax_proc_office_cnpj_idx');
            $table->index(['office_id', 'status'], 'fiscal_tax_proc_office_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_tax_processes');
        Schema::dropIfExists('fiscal_registration_links');
    }
};
