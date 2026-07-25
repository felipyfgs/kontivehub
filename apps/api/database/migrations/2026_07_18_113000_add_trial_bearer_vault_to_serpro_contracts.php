<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('serpro_contracts', function (Blueprint $table): void {
            $table->string('trial_bearer_vault_object_id', 26)
                ->nullable()
                ->after('oauth_vault_object_id');
        });
    }

    public function down(): void
    {
        Schema::table('serpro_contracts', function (Blueprint $table): void {
            $table->dropColumn('trial_bearer_vault_object_id');
        });
    }
};
