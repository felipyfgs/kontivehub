<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_sticker_contents', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->char('public_id', 26)->unique();
            $table->char('sha256', 64);
            $table->text('object_id_encrypted');
            $table->text('storage_context_encrypted');
            $table->string('mime_type', 64)->default('image/webp');
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->boolean('animated')->default(false);
            $table->string('provenance', 32);
            $table->boolean('retention_protected')->default(false);
            $table->timestampTz('last_referenced_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'sha256']);
            $table->index(['tenant_id', 'expires_at', 'retention_protected'], 'communication_sticker_contents_retention_idx');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });

        Schema::create('communication_sticker_observations', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('inbox_id');
            $table->bigInteger('content_id')->nullable();
            $table->char('public_id', 26)->unique();
            $table->string('observation_id', 128);
            $table->string('source', 32);
            $table->string('availability', 32);
            $table->string('unavailable_reason', 64)->nullable();
            $table->boolean('device_favorite')->default(false);
            $table->boolean('app_favorite')->default(false);
            $table->text('metadata_encrypted')->nullable();
            $table->timestampTz('device_favorite_observed_at')->nullable();
            $table->timestampTz('last_observed_at');
            $table->timestampTz('removed_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();

            $table->unique(['inbox_id', 'observation_id']);
            $table->index(['tenant_id', 'inbox_id', 'removed_at', 'last_observed_at'], 'communication_sticker_observations_library_idx');
            $table->index(['tenant_id', 'inbox_id', 'app_favorite', 'device_favorite'], 'communication_sticker_observations_favorites_idx');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('inbox_id')->references('id')->on('communication_inboxes')->cascadeOnDelete();
            $table->foreign('content_id')->references('id')->on('communication_sticker_contents')->nullOnDelete();
        });

        Schema::create('communication_sticker_sync_watermarks', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('inbox_id');
            $table->string('status', 32)->default('NOT_OBSERVED');
            $table->string('reason_code', 64)->nullable();
            $table->string('last_gateway_event_id', 128)->nullable();
            $table->timestampTz('last_observed_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'inbox_id']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('inbox_id')->references('id')->on('communication_inboxes')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_sticker_sync_watermarks');
        Schema::dropIfExists('communication_sticker_observations');
        Schema::dropIfExists('communication_sticker_contents');
    }
};
