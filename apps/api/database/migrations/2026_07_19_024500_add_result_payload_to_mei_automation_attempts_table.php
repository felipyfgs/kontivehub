<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mei_automation_attempts', function (Blueprint $table): void {
            $table->longText('result_payload_encrypted')->nullable()->after('vault_artifacts');
        });
    }

    public function down(): void
    {
        Schema::table('mei_automation_attempts', function (Blueprint $table): void {
            $table->dropColumn('result_payload_encrypted');
        });
    }
};
