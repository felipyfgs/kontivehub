<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('communication_messages', function (Blueprint $table): void {
            $table->string('provider_type', 80)->nullable()->after('kind');
            $table->longText('content_encrypted')->nullable()->after('body_encrypted');
            $table->timestampTz('played_at')->nullable()->after('read_at');
            $table->timestampTz('revoked_at')->nullable()->after('played_at');
            $table->index(['office_id', 'provider_type'], 'comm_messages_provider_type_idx');
            $table->index(['office_id', 'revoked_at'], 'comm_messages_revoked_idx');
        });
    }

    public function down(): void
    {
        Schema::table('communication_messages', function (Blueprint $table): void {
            $table->dropIndex('comm_messages_provider_type_idx');
            $table->dropIndex('comm_messages_revoked_idx');
            $table->dropColumn(['provider_type', 'content_encrypted', 'played_at', 'revoked_at']);
        });
    }
};
