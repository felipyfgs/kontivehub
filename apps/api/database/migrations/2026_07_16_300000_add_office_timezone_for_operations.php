<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Timezone IANA do escritório para prazos/fila/calendário operacionais.
 * Backfill America/Sao_Paulo; reutiliza deadline_timezone quando válido.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('offices', 'timezone')) {
            Schema::table('offices', function (Blueprint $table): void {
                $table->string('timezone', 64)->default('America/Sao_Paulo')->after('is_active');
            });
        }

        // Backfill: preferir deadline_timezone válido; senão America/Sao_Paulo
        if (Schema::hasColumn('offices', 'deadline_timezone')) {
            DB::table('offices')
                ->whereNotNull('deadline_timezone')
                ->where('deadline_timezone', '!=', '')
                ->orderBy('id')
                ->each(function (object $row): void {
                    $tz = (string) $row->deadline_timezone;
                    try {
                        new DateTimeZone($tz);
                        DB::table('offices')->where('id', $row->id)->update(['timezone' => $tz]);
                    } catch (Exception) {
                        DB::table('offices')->where('id', $row->id)->update(['timezone' => 'America/Sao_Paulo']);
                    }
                });
        }

        DB::table('offices')
            ->whereNull('timezone')
            ->orWhere('timezone', '')
            ->update(['timezone' => 'America/Sao_Paulo']);
    }

    public function down(): void
    {
        if (Schema::hasColumn('offices', 'timezone')) {
            Schema::table('offices', function (Blueprint $table): void {
                $table->dropColumn('timezone');
            });
        }
    }
};
