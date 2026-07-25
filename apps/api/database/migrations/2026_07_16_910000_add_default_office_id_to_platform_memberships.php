<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Office padrão global para PLATFORM_ADMIN (sem criar OfficeMembership).
 *
 * Backfill: Office ativo mais antigo (id ASC) apenas para memberships sem valor.
 * Office padrão inativo não é substituído silenciosamente em runtime.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_memberships', function (Blueprint $table) {
            $table->foreignId('default_office_id')
                ->nullable()
                ->after('is_active')
                ->constrained('offices')
                ->nullOnDelete();
        });

        $defaultOfficeId = DB::table('offices')
            ->where('is_active', true)
            ->orderBy('id')
            ->value('id');

        if ($defaultOfficeId === null) {
            Log::info('platform_memberships.default_office_id backfill skipped: no active office', [
                'decision' => 'no_active_office',
            ]);

            return;
        }

        $updated = DB::table('platform_memberships')
            ->whereNull('default_office_id')
            ->update(['default_office_id' => $defaultOfficeId]);

        Log::info('platform_memberships.default_office_id backfill', [
            'decision' => 'oldest_active_office',
            'default_office_id' => (int) $defaultOfficeId,
            'rows_updated' => $updated,
            'office_memberships_created' => 0,
        ]);
    }

    public function down(): void
    {
        Schema::table('platform_memberships', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_office_id');
        });
    }
};
