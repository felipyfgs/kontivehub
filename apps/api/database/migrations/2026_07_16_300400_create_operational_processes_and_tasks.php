<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operational_processes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('process_template_id')->nullable()->constrained('process_templates')->nullOnDelete();
            $table->foreignId('generation_batch_id')->nullable()->constrained('process_generation_batches')->nullOnDelete();
            $table->string('origin', 20)->default('MANUAL');
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('competence', 7);
            $table->date('due_date')->nullable();
            $table->date('target_due_date')->nullable();
            $table->boolean('subject_to_fine')->default(false);
            $table->foreignId('work_department_id')->nullable()->constrained('work_departments')->nullOnDelete();
            $table->foreignId('assignee_membership_id')->nullable()->constrained('office_user')->nullOnDelete();
            $table->string('status', 32)->default('A_FAZER');
            $table->json('template_snapshot')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->foreignId('created_by_membership_id')->nullable()->constrained('office_user')->nullOnDelete();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->timestamps();

            $table->index(['office_id', 'status']);
            $table->index(['office_id', 'competence']);
            $table->index(['office_id', 'client_id']);
            $table->index(['office_id', 'assignee_membership_id']);
            $table->index(['office_id', 'work_department_id']);
            $table->index(['office_id', 'due_date']);
        });

        // Unicidade TEMPLATE: office + template + client + competence (somente origem TEMPLATE)
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("
                CREATE UNIQUE INDEX operational_processes_template_unique
                ON operational_processes (office_id, process_template_id, client_id, competence)
                WHERE origin = 'TEMPLATE' AND process_template_id IS NOT NULL
            ");
        } else {
            // SQLite/tests: índice parcial não disponível da mesma forma; unique composto
            // com origin embutido via coluna gerada simulada — partial via where no app + unique
            // com nullable template. Usamos unique parcial se suportado.
            try {
                DB::statement("
                    CREATE UNIQUE INDEX operational_processes_template_unique
                    ON operational_processes (office_id, process_template_id, client_id, competence)
                    WHERE origin = 'TEMPLATE' AND process_template_id IS NOT NULL
                ");
            } catch (Throwable) {
                // fallback sem partial (testes ainda cobrem via app)
            }
        }

        Schema::create('operational_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('operational_process_id')->constrained('operational_processes')->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order');
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('status', 32)->default('A_FAZER');
            $table->date('due_date')->nullable();
            $table->date('target_due_date')->nullable();
            $table->foreignId('work_department_id')->nullable()->constrained('work_departments')->nullOnDelete();
            $table->foreignId('assignee_membership_id')->nullable()->constrained('office_user')->nullOnDelete();
            $table->boolean('is_required')->default(true);
            $table->boolean('is_critical')->default(false);
            $table->boolean('requires_evidence')->default(false);
            $table->text('block_reason')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->foreignId('started_by_membership_id')->nullable()->constrained('office_user')->nullOnDelete();
            $table->foreignId('completed_by_membership_id')->nullable()->constrained('office_user')->nullOnDelete();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['operational_process_id', 'sort_order']);
            $table->index(['office_id', 'status']);
            $table->index(['office_id', 'assignee_membership_id', 'status']);
            $table->index(['office_id', 'work_department_id', 'status']);
            $table->index(['office_id', 'due_date']);
            $table->index(['office_id', 'operational_process_id']);
        });

        // FK tardia do item de geração → processo criado
        Schema::table('process_generation_items', function (Blueprint $table): void {
            $table->foreign('created_process_id')
                ->references('id')
                ->on('operational_processes')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('process_generation_items', function (Blueprint $table): void {
            $table->dropForeign(['created_process_id']);
        });

        Schema::dropIfExists('operational_tasks');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS operational_processes_template_unique');
        } else {
            try {
                DB::statement('DROP INDEX IF EXISTS operational_processes_template_unique');
            } catch (Throwable) {
            }
        }

        Schema::dropIfExists('operational_processes');
    }
};
