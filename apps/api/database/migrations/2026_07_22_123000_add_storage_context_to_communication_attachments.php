<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('communication_attachments', function (Blueprint $table): void {
            $table->json('storage_context')->nullable()->after('sha256');
        });
    }

    public function down(): void
    {
        Schema::table('communication_attachments', function (Blueprint $table): void {
            $table->dropColumn('storage_context');
        });
    }
};
