<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_inbox_identity_profiles', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('inbox_id');
            $table->bigInteger('identity_id');
            $table->string('address_book_first_name', 512)->nullable();
            $table->string('address_book_full_name', 512)->nullable();
            $table->string('verified_name', 512)->nullable();
            $table->string('business_name', 512)->nullable();
            $table->string('push_name', 512)->nullable();
            $table->string('picture_id', 512)->nullable();
            $table->string('about', 2048)->nullable();
            $table->jsonb('field_versions')->default('{}');
            $table->jsonb('cleared_fields')->default('[]');
            $table->timestampsTz();

            $table->unique(['tenant_id', 'inbox_id', 'identity_id'], 'communication_inbox_identity_profiles_unique');
            $table->index(['tenant_id', 'inbox_id', 'identity_id'], 'communication_inbox_identity_profiles_lookup_idx');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['inbox_id'])->references(['id'])->on('communication_inboxes')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['identity_id'])->references(['id'])->on('communication_identities')->onUpdate('no action')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_inbox_identity_profiles');
    }
};
