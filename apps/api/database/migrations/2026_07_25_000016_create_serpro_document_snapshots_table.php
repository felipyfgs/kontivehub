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
        Schema::create('serpro_document_snapshots', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('source_key', 80);
            $table->string('title', 200);
            $table->string('url', 1000)->nullable();
            $table->string('content_sha256', 64);
            $table->string('document_type', 40);
            $table->string('revision', 80)->nullable();
            $table->date('retrieved_on')->nullable();
            $table->jsonb('affected_capabilities')->nullable();
            $table->string('segregation_class', 40)->default('PRODUCTION');
            $table->text('notes')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->unique(['source_key', 'content_sha256']);
            $table->index(['source_key', 'retrieved_on']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('serpro_document_snapshots');
    }
};
