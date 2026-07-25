<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_sync_cursors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('establishment_id')->constrained()->cascadeOnDelete();
            $table->string('environment', 40);
            $table->string('source', 20); // ADN | SEFAZ
            $table->string('channel', 40); // NFSE_ADN | NFE_DISTDFE | …
            $table->unsignedBigInteger('last_nsu')->default(0);
            $table->unsignedBigInteger('max_nsu_seen')->nullable();
            $table->string('status', 32)->default('IDLE');
            $table->string('last_cstat', 10)->nullable();
            $table->string('last_xmotivo', 255)->nullable();
            $table->unsignedInteger('consecutive_decode_failures')->default(0);
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('next_sync_at')->nullable();
            $table->timestampTz('last_success_at')->nullable();
            $table->timestampTz('locked_at')->nullable();
            $table->string('lock_owner')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(
                ['establishment_id', 'environment', 'source', 'channel'],
                'channel_sync_cursors_unique'
            );
            $table->index(['status', 'next_sync_at']);
            $table->index(['office_id', 'channel']);
        });

        Schema::create('nfe_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dfe_document_id')->constrained()->cascadeOnDelete();
            $table->string('access_key', 50);
            $table->string('number', 20)->nullable();
            $table->string('series', 10)->nullable();
            $table->string('model', 5)->default('55');
            $table->string('issuer_cnpj', 14)->nullable();
            $table->string('issuer_name')->nullable();
            $table->string('recipient_cnpj', 14)->nullable();
            $table->string('recipient_name')->nullable();
            $table->string('fiscal_role', 20)->nullable();
            $table->timestampTz('issued_at')->nullable();
            $table->decimal('total_amount', 15, 2)->nullable();
            $table->string('status', 32)->default('UNKNOWN');
            $table->string('official_status_code', 10)->nullable();
            $table->boolean('is_summary')->default(false);
            $table->string('manifestation_status', 40)->nullable();
            $table->string('schema_hint', 80)->nullable();
            $table->timestamps();

            $table->unique(['office_id', 'access_key', 'is_summary'], 'nfe_documents_office_key_summary');
            $table->index(['office_id', 'issued_at']);
            $table->index(['office_id', 'issuer_cnpj']);
            $table->index(['office_id', 'recipient_cnpj']);
            $table->index(['office_id', 'status']);
            $table->index(['office_id', 'manifestation_status']);
        });

        Schema::create('nfe_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dfe_document_id')->constrained()->cascadeOnDelete();
            $table->string('access_key', 50);
            $table->string('event_type', 40)->nullable();
            $table->unsignedSmallInteger('sequence')->nullable();
            $table->timestampTz('event_at')->nullable();
            $table->string('status', 32)->nullable();
            $table->string('protocol', 40)->nullable();
            $table->timestamps();

            $table->index(['office_id', 'access_key']);
        });

        // NSU é por canal — ADN e DistDFe não podem colidir no mesmo unique.
        Schema::table('document_interests', function (Blueprint $table) {
            $table->string('channel', 40)->default('NFSE_ADN')->after('environment');
        });
        // Drop unique antigo e recria com channel (nome default do Laravel)
        Schema::table('document_interests', function (Blueprint $table) {
            $table->dropUnique(['establishment_id', 'environment', 'nsu']);
        });
        Schema::table('document_interests', function (Blueprint $table) {
            $table->unique(
                ['establishment_id', 'environment', 'channel', 'nsu'],
                'document_interests_estab_env_channel_nsu_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('document_interests', function (Blueprint $table) {
            $table->dropUnique('document_interests_estab_env_channel_nsu_unique');
        });
        Schema::table('document_interests', function (Blueprint $table) {
            $table->dropColumn('channel');
            $table->unique(['establishment_id', 'environment', 'nsu']);
        });

        Schema::dropIfExists('nfe_events');
        Schema::dropIfExists('nfe_documents');
        Schema::dropIfExists('channel_sync_cursors');
    }
};
