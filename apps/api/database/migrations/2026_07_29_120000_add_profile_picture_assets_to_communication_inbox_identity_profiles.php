<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('communication_inbox_identity_profiles', function (Blueprint $table): void {
            $table->string('profile_picture_state', 16)->default('UNKNOWN');
            $table->string('profile_picture_object_id', 64)->nullable();
            $table->string('profile_picture_mime_type', 32)->nullable();
            $table->unsignedInteger('profile_picture_size_bytes')->nullable();
            $table->string('profile_picture_sha256', 64)->nullable();
            $table->jsonb('profile_picture_storage_context')->nullable();
            $table->unsignedBigInteger('profile_picture_version')->default(0);
            $table->timestampTz('profile_picture_fetched_at')->nullable();
            $table->timestampTz('profile_picture_retry_at')->nullable();
            $table->string('profile_picture_error_code', 64)->nullable();
            $table->index(['tenant_id', 'profile_picture_state', 'profile_picture_retry_at'], 'communication_profile_picture_dispatch_idx');
        });
        DB::statement("ALTER TABLE communication_inbox_identity_profiles ADD CONSTRAINT communication_profile_picture_state_chk CHECK (profile_picture_state IN ('UNKNOWN', 'PENDING', 'READY', 'UNAVAILABLE', 'FAILED'))");
        DB::statement('ALTER TABLE communication_inbox_identity_profiles ADD CONSTRAINT communication_profile_picture_version_chk CHECK (profile_picture_version >= 0)');
        DB::statement('ALTER TABLE communication_inbox_identity_profiles ADD CONSTRAINT communication_profile_picture_size_chk CHECK (profile_picture_size_bytes IS NULL OR profile_picture_size_bytes BETWEEN 1 AND 2097152)');
        DB::statement("ALTER TABLE communication_inbox_identity_profiles ADD CONSTRAINT communication_profile_picture_mime_chk CHECK (profile_picture_mime_type IS NULL OR profile_picture_mime_type IN ('image/jpeg', 'image/png', 'image/webp'))");
        DB::statement("ALTER TABLE communication_inbox_identity_profiles ADD CONSTRAINT communication_profile_picture_sha_chk CHECK (profile_picture_sha256 IS NULL OR profile_picture_sha256 ~ '^[a-f0-9]{64}$')");
        DB::statement(<<<'SQL'
            ALTER TABLE communication_inbox_identity_profiles
            ADD CONSTRAINT communication_profile_picture_ready_chk CHECK (
                profile_picture_state <> 'READY' OR (
                    profile_picture_version > 0
                    AND profile_picture_object_id IS NOT NULL
                    AND profile_picture_object_id ~ '^[0-9A-HJKMNP-TV-Z]{26}$'
                    AND profile_picture_mime_type IS NOT NULL
                    AND profile_picture_size_bytes IS NOT NULL
                    AND profile_picture_sha256 IS NOT NULL
                    AND profile_picture_storage_context IS NOT NULL
                    AND jsonb_typeof(profile_picture_storage_context) = 'object'
                    AND profile_picture_storage_context ->> 'tenant_id' = tenant_id::text
                    AND profile_picture_storage_context ->> 'inbox_id' = inbox_id::text
                    AND profile_picture_storage_context ->> 'profile_id' = id::text
                    AND profile_picture_storage_context ->> 'version' = profile_picture_version::text
                    AND profile_picture_storage_context ->> 'purpose' = 'COMMUNICATION_MEDIA'
                    AND profile_picture_fetched_at IS NOT NULL
                )
            )
            SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE communication_inbox_identity_profiles DROP CONSTRAINT IF EXISTS communication_profile_picture_ready_chk');
        DB::statement('ALTER TABLE communication_inbox_identity_profiles DROP CONSTRAINT IF EXISTS communication_profile_picture_sha_chk');
        DB::statement('ALTER TABLE communication_inbox_identity_profiles DROP CONSTRAINT IF EXISTS communication_profile_picture_mime_chk');
        DB::statement('ALTER TABLE communication_inbox_identity_profiles DROP CONSTRAINT IF EXISTS communication_profile_picture_size_chk');
        DB::statement('ALTER TABLE communication_inbox_identity_profiles DROP CONSTRAINT IF EXISTS communication_profile_picture_version_chk');
        DB::statement('ALTER TABLE communication_inbox_identity_profiles DROP CONSTRAINT IF EXISTS communication_profile_picture_state_chk');
        Schema::table('communication_inbox_identity_profiles', function (Blueprint $table): void {
            $table->dropIndex('communication_profile_picture_dispatch_idx');
            $table->dropColumn(['profile_picture_state', 'profile_picture_object_id', 'profile_picture_mime_type', 'profile_picture_size_bytes', 'profile_picture_sha256', 'profile_picture_storage_context', 'profile_picture_version', 'profile_picture_fetched_at', 'profile_picture_retry_at', 'profile_picture_error_code']);
        });
    }
};
