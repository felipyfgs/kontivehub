<?php

use App\Domain\Work\ReferencePeriod;
use App\Enums\Work\ProcessStatus;
use App\Enums\Work\TaskStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Formaliza Período de referência tipado e arquivamento ortogonal ao progresso.
 *
 * - `competence` permanece como chave canônica do período (compat; cabe YYYY-MM / YYYY-Tn / YYYY).
 * - Colunas tipadas: type / start / end em processos e lotes.
 * - Status ARQUIVADO migrado para archived_at + status derivado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operational_processes', function (Blueprint $table): void {
            $table->string('reference_period_type', 16)->nullable()->after('competence');
            $table->date('reference_period_start')->nullable()->after('reference_period_type');
            $table->date('reference_period_end')->nullable()->after('reference_period_start');
            $table->index(['office_id', 'reference_period_type', 'competence'], 'op_office_period_type_key_idx');
        });

        Schema::table('process_generation_batches', function (Blueprint $table): void {
            $table->string('reference_period_type', 16)->nullable()->after('competence');
            $table->date('reference_period_start')->nullable()->after('reference_period_type');
            $table->date('reference_period_end')->nullable()->after('reference_period_start');
            $table->index(
                ['office_id', 'process_template_id', 'reference_period_type', 'competence'],
                'pgb_office_template_period_idx',
            );
        });

        $this->backfillPeriods('operational_processes');
        $this->backfillPeriods('process_generation_batches');
        $this->migrateArchivedStatus();
    }

    public function down(): void
    {
        Schema::table('operational_processes', function (Blueprint $table): void {
            $table->dropIndex('op_office_period_type_key_idx');
            $table->dropColumn(['reference_period_type', 'reference_period_start', 'reference_period_end']);
        });

        Schema::table('process_generation_batches', function (Blueprint $table): void {
            $table->dropIndex('pgb_office_template_period_idx');
            $table->dropColumn(['reference_period_type', 'reference_period_start', 'reference_period_end']);
        });
    }

    private function backfillPeriods(string $table): void
    {
        DB::table($table)
            ->orderBy('id')
            ->select(['id', 'competence'])
            ->chunkById(200, function ($rows) use ($table): void {
                foreach ($rows as $row) {
                    try {
                        $period = ReferencePeriod::fromString((string) $row->competence);
                    } catch (Throwable) {
                        // Dados legados inválidos: trata como mensal se possível YYYY-MM parcial.
                        continue;
                    }

                    DB::table($table)->where('id', $row->id)->update([
                        'reference_period_type' => $period->type->value,
                        'reference_period_start' => $period->startDate(),
                        'reference_period_end' => $period->endDate(),
                        'competence' => $period->value(),
                    ]);
                }
            });
    }

    private function migrateArchivedStatus(): void
    {
        $archived = DB::table('operational_processes')
            ->where('status', 'ARQUIVADO')
            ->orderBy('id')
            ->get(['id', 'archived_at']);

        foreach ($archived as $row) {
            $taskStatuses = DB::table('operational_tasks')
                ->where('operational_process_id', $row->id)
                ->pluck('status')
                ->all();

            $derived = $this->deriveStatus($taskStatuses);

            DB::table('operational_processes')->where('id', $row->id)->update([
                'status' => $derived,
                'archived_at' => $row->archived_at ?? now(),
            ]);
        }
    }

    /**
     * @param  list<string>  $statuses
     */
    private function deriveStatus(array $statuses): string
    {
        if ($statuses === []) {
            return ProcessStatus::AFazer->value;
        }

        foreach ($statuses as $status) {
            if ($status === TaskStatus::Impedida->value) {
                return ProcessStatus::Impedido->value;
            }
        }

        $allTerminal = true;
        $allToDo = true;
        foreach ($statuses as $status) {
            if (! in_array($status, [TaskStatus::Concluida->value, TaskStatus::Dispensada->value], true)) {
                $allTerminal = false;
            }
            if ($status !== TaskStatus::AFazer->value) {
                $allToDo = false;
            }
        }

        if ($allTerminal) {
            return ProcessStatus::Concluido->value;
        }
        if ($allToDo) {
            return ProcessStatus::AFazer->value;
        }

        return ProcessStatus::EmProgresso->value;
    }
};
