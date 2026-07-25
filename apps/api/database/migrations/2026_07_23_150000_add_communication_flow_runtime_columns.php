<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('communication_flow_runs', function (Blueprint $table): void {
            $table->text('context_encrypted')->nullable()->after('current_node_id');
            $table->timestampTz('waiting_until')->nullable()->after('finished_at');
            $table->string('waiting_effect_key', 160)->nullable()->after('waiting_until');
            $table->foreignId('waiting_outbox_entry_id')->nullable()->after('waiting_effect_key')
                ->constrained('communication_outbox_entries')->nullOnDelete();
        });

        Schema::table('communication_flow_run_steps', function (Blueprint $table): void {
            $table->unsignedInteger('seq')->default(1)->after('node_type');
            $table->string('effect_key', 160)->nullable()->after('status');
            $table->unique(['run_id', 'node_id', 'seq'], 'comm_flow_run_steps_run_node_seq_uq');
            $table->unique(['run_id', 'effect_key'], 'comm_flow_run_steps_run_effect_uq');
        });

        Schema::table('communication_outbox_entries', function (Blueprint $table): void {
            $table->string('effect_key', 160)->nullable()->after('command_id');
            $table->unique('effect_key', 'comm_outbox_effect_key_uq');
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX comm_flow_runs_one_active_per_conversation
            ON communication_flow_runs (conversation_id)
            WHERE conversation_id IS NOT NULL
              AND status IN (
                'pending',
                'running',
                'waiting_input',
                'waiting_delay',
                'waiting_outbox',
                'paused'
              )
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS comm_flow_runs_one_active_per_conversation');

        Schema::table('communication_outbox_entries', function (Blueprint $table): void {
            $table->dropUnique('comm_outbox_effect_key_uq');
            $table->dropColumn('effect_key');
        });

        Schema::table('communication_flow_run_steps', function (Blueprint $table): void {
            $table->dropUnique('comm_flow_run_steps_run_effect_uq');
            $table->dropUnique('comm_flow_run_steps_run_node_seq_uq');
            $table->dropColumn(['seq', 'effect_key']);
        });

        Schema::table('communication_flow_runs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('waiting_outbox_entry_id');
            $table->dropColumn(['context_encrypted', 'waiting_until', 'waiting_effect_key']);
        });
    }
};
