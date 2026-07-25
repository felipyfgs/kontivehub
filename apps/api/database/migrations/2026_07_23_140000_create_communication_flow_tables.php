<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_flows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('status', 32)->default('paused');
            $table->unsignedInteger('lock_version')->default(1);
            $table->foreignId('created_by_membership_id')->nullable()->constrained('office_user')->nullOnDelete();
            $table->timestamps();

            $table->index(['office_id', 'status'], 'comm_flows_office_status_idx');
            $table->unique(['office_id', 'name'], 'comm_flows_office_name_uq');
        });

        Schema::create('communication_flow_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('flow_id')->constrained('communication_flows')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->text('graph_encrypted');
            $table->char('graph_digest', 64);
            $table->timestampTz('published_at');
            $table->foreignId('published_by_membership_id')->nullable()->constrained('office_user')->nullOnDelete();
            $table->timestamps();

            $table->unique(['flow_id', 'version'], 'comm_flow_versions_flow_version_uq');
            $table->index(['office_id', 'flow_id'], 'comm_flow_versions_office_flow_idx');
        });

        Schema::create('communication_flow_drafts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('flow_id')->constrained('communication_flows')->cascadeOnDelete();
            $table->text('graph_encrypted');
            $table->char('graph_digest', 64);
            $table->unsignedInteger('lock_version')->default(1);
            $table->foreignId('updated_by_membership_id')->nullable()->constrained('office_user')->nullOnDelete();
            $table->timestamps();

            $table->unique(['flow_id'], 'comm_flow_drafts_flow_uq');
            $table->index(['office_id'], 'comm_flow_drafts_office_idx');
        });

        Schema::create('communication_flow_inbox_bindings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('flow_id')->constrained('communication_flows')->cascadeOnDelete();
            $table->foreignId('inbox_id')->constrained('communication_inboxes')->cascadeOnDelete();
            $table->foreignId('published_version_id')->nullable()
                ->constrained('communication_flow_versions')->nullOnDelete();
            $table->boolean('enabled')->default(false);
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->unique(['flow_id', 'inbox_id'], 'comm_flow_bindings_flow_inbox_uq');
            $table->index(['office_id', 'inbox_id'], 'comm_flow_bindings_office_inbox_idx');
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX comm_flow_bindings_one_enabled_per_inbox
            ON communication_flow_inbox_bindings (inbox_id)
            WHERE enabled = true
            SQL);

        Schema::create('communication_flow_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('flow_id')->constrained('communication_flows')->cascadeOnDelete();
            $table->foreignId('flow_version_id')->constrained('communication_flow_versions')->cascadeOnDelete();
            $table->foreignId('binding_id')->nullable()
                ->constrained('communication_flow_inbox_bindings')->nullOnDelete();
            $table->foreignId('conversation_id')->nullable()
                ->constrained('communication_conversations')->nullOnDelete();
            $table->string('status', 32)->default('pending');
            $table->string('current_node_id', 64)->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestamps();

            $table->index(['office_id', 'status'], 'comm_flow_runs_office_status_idx');
        });

        Schema::create('communication_flow_run_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('run_id')->constrained('communication_flow_runs')->cascadeOnDelete();
            $table->string('node_id', 64);
            $table->string('node_type', 40);
            $table->string('status', 32)->default('pending');
            $table->timestampTz('entered_at')->nullable();
            $table->timestampTz('exited_at')->nullable();
            $table->json('result_meta')->nullable();
            $table->timestamps();

            $table->index(['run_id', 'entered_at'], 'comm_flow_run_steps_run_entered_idx');
        });

        Schema::create('communication_flow_consumptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('run_id')->nullable()->constrained('communication_flow_runs')->nullOnDelete();
            $table->foreignId('conversation_id')->nullable()
                ->constrained('communication_conversations')->nullOnDelete();
            $table->string('event_key', 128);
            $table->char('event_digest', 64)->nullable();
            $table->timestampTz('consumed_at')->nullable();
            $table->timestamps();

            $table->unique(['office_id', 'event_key'], 'comm_flow_consumptions_office_event_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_flow_consumptions');
        Schema::dropIfExists('communication_flow_run_steps');
        Schema::dropIfExists('communication_flow_runs');
        DB::statement('DROP INDEX IF EXISTS comm_flow_bindings_one_enabled_per_inbox');
        Schema::dropIfExists('communication_flow_inbox_bindings');
        Schema::dropIfExists('communication_flow_drafts');
        Schema::dropIfExists('communication_flow_versions');
        Schema::dropIfExists('communication_flows');
    }
};
