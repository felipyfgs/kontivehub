<?php

use Illuminate\Database\Migrations\Migration;

/**
 * No-op documentado — create + backfill vivem em
 * 2026_07_16_900104_create_office_serpro_onboarding_states_table.php.
 *
 * Este arquivo permanece no histórico (ordem 900401) para ambientes que
 * já o registraram em `migrations`. Não recria a tabela; `migrate:fresh`
 * cria `office_serpro_onboarding_states` uma única vez via 900104.
 *
 * @see docs/ops/schema-conventions.md
 */
return new class extends Migration
{
    public function up(): void
    {
        // no-op: fonte do create é 900104
    }

    public function down(): void
    {
        // no-op: drop permanece em 900104::down
    }
};
