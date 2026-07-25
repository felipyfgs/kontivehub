<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Política explícita por aprovação SERPRO.
 *
 * Histórico → DUAL_ROLE (nunca reinterpretar como OWNER_CONFIRMATION).
 * Pendências das ações globais (kill-off / contrato / cutover) expiram e devem ser refeitas.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const OWNER_ACTIONS = [
        'KILL_SWITCH_OFF',
        'KILL_SWITCH_SOLUTION_OFF',
        'CONTRACT_ACTIVATE',
        'CREDENTIAL_CUTOVER',
    ];

    public function up(): void
    {
        Schema::table('serpro_rollout_approvals', function (Blueprint $table) {
            $table->string('approval_policy', 40)->default('DUAL_ROLE')->after('action');
            $table->string('confirmation_phrase', 120)->nullable()->after('reason');
            $table->timestampTz('change_window_start')->nullable()->after('expires_at');
            $table->timestampTz('change_window_end')->nullable()->after('change_window_start');
        });

        // Histórico e pendências legadas: política dual (não backfill para OWNER).
        if (Schema::hasTable('serpro_rollout_approvals')) {
            DB::table('serpro_rollout_approvals')->update([
                'approval_policy' => 'DUAL_ROLE',
            ]);

            // Expira pendências das ações globais incompatíveis com o modelo de proprietário único.
            DB::table('serpro_rollout_approvals')
                ->whereIn('action', self::OWNER_ACTIONS)
                ->whereIn('status', ['PENDING', 'PARTIAL', 'APPROVED'])
                ->whereNull('executed_at')
                ->update([
                    'status' => 'EXPIRED',
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('serpro_rollout_approvals', function (Blueprint $table) {
            $table->dropColumn([
                'approval_policy',
                'confirmation_phrase',
                'change_window_start',
                'change_window_end',
            ]);
        });
    }
};
