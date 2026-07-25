<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pagtoweb_arrecadacao_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->constrained('offices')->restrictOnDelete();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->string('receipt_vault_object_id', 26);
            $table->char('receipt_sha256', 64);
            $table->string('receipt_mime_type', 100);
            $table->unsignedInteger('receipt_byte_size');
            $table->string('source_provenance', 32);
            $table->timestampTz('observed_at');
            $table->timestamps();

            $table->unique(['office_id', 'client_id', 'receipt_sha256'], 'pagtoweb_receipts_office_client_sha_uq');
            $table->index(['office_id', 'client_id', 'observed_at'], 'pagtoweb_receipts_client_observed_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pagtoweb_arrecadacao_receipts');
    }
};
