<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_media_deletion_intents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('object_id', 26)->unique();
            $table->timestampTz('due_at')->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('last_error_code', 64)->nullable();
            $table->timestampTz('dispatched_at')->nullable();
            $table->timestampTz('deleted_at')->nullable()->index();
            $table->timestampTz('failed_at')->nullable()->index();
            $table->timestampsTz();
            $table->index(['deleted_at', 'failed_at', 'due_at'], 'communication_media_delete_due_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_media_deletion_intents');
    }
};
