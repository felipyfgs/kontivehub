<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table): void {
            // Singleton: a chave fixa 1 também arbitra conclusões concorrentes.
            $table->unsignedTinyInteger('id')->primary();
            $table->string('organization_name');
            $table->timestamp('onboarding_completed_at')->nullable();
            $table->foreignId('onboarded_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();
        });

        $alreadyInitialized = DB::table('users')->exists()
            || DB::table('offices')->exists()
            || DB::table('platform_memberships')->exists();

        if (! $alreadyInitialized) {
            return;
        }

        $platformAdminId = DB::table('platform_memberships')
            ->where('role', 'PLATFORM_ADMIN')
            ->where('is_active', true)
            ->orderBy('id')
            ->value('user_id');

        $now = now();
        DB::table('platform_settings')->insert([
            'id' => 1,
            'organization_name' => mb_substr(trim((string) config('app.name', 'Plataforma')) ?: 'Plataforma', 0, 255),
            'onboarding_completed_at' => $now,
            'onboarded_by_user_id' => $platformAdminId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
    }
};
