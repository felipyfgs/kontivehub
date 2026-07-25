<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Preserva legado sintético para reconciliação, mas o torna inelegível para
 * estado operacional, KPI, prontidão ou alegação de fonte real.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->addQuarantineColumns('esocial_event_evidences');
        $this->addQuarantineColumns('fgts_competence_statuses');
        $this->addQuarantineColumns('fiscal_guide_stubs');

        $metadataText = DB::getDriverName() === 'pgsql'
            ? "LOWER(COALESCE(metadata::text, ''))"
            : "LOWER(COALESCE(metadata, ''))";

        if (Schema::hasTable('esocial_event_evidences')) {
            DB::table('esocial_event_evidences')
                ->where(function ($query) use ($metadataText): void {
                    $query->whereRaw("LOWER(COALESCE(source_version, '')) = ?", ['fake-1'])
                        ->orWhereRaw("LOWER(COALESCE(source, '')) IN (?, ?)", ['fake', 'simulated'])
                        ->orWhereRaw("{$metadataText} LIKE ?", ['%"simulated":true%'])
                        ->orWhereRaw("{$metadataText} LIKE ?", ['%"simulated":1%']);
                })
                ->update([
                    'is_quarantined' => true,
                    'quarantine_reason' => 'SYNTHETIC_ESOCIAL_LEGACY',
                    'quarantined_at' => now(),
                ]);

            if (Schema::hasTable('fiscal_evidence_artifacts')) {
                DB::table('fiscal_evidence_artifacts')
                    ->whereIn('id', DB::table('esocial_event_evidences')
                        ->where('is_quarantined', true)
                        ->whereNotNull('fiscal_evidence_artifact_id')
                        ->select('fiscal_evidence_artifact_id'))
                    ->update([
                        'source_provenance' => 'UNVERIFIED',
                        'verification_state' => 'REJECTED',
                    ]);
            }

            if (Schema::hasTable('fiscal_monitoring_runs')) {
                DB::table('fiscal_monitoring_runs')
                    ->whereIn('id', DB::table('esocial_event_evidences')
                        ->where('is_quarantined', true)
                        ->whereNotNull('run_id')
                        ->select('run_id'))
                    ->update([
                        'source_provenance' => 'UNVERIFIED',
                        'verification_state' => 'REJECTED',
                    ]);
            }

            if (Schema::hasTable('fiscal_snapshots')) {
                DB::table('fiscal_snapshots')
                    ->whereIn('run_id', DB::table('esocial_event_evidences')
                        ->where('is_quarantined', true)
                        ->whereNotNull('run_id')
                        ->select('run_id'))
                    ->update([
                        'source_provenance' => 'UNVERIFIED',
                        'verification_state' => 'REJECTED',
                        'is_current' => false,
                    ]);
            }

            if (Schema::hasTable('fgts_competence_statuses')) {
                DB::table('fgts_competence_statuses')
                    ->whereExists(function ($query): void {
                        $query->selectRaw('1')
                            ->from('esocial_event_evidences as eee')
                            ->whereColumn('eee.office_id', 'fgts_competence_statuses.office_id')
                            ->whereColumn('eee.client_id', 'fgts_competence_statuses.client_id')
                            ->whereColumn('eee.competence_period_key', 'fgts_competence_statuses.competence_period_key')
                            ->where('eee.is_quarantined', true);
                    })
                    ->update([
                        'is_quarantined' => true,
                        'quarantine_reason' => 'SYNTHETIC_ESOCIAL_LEGACY',
                        'quarantined_at' => now(),
                    ]);
            }
        }

        if (Schema::hasTable('fiscal_evidence_artifacts')) {
            DB::table('fiscal_evidence_artifacts')
                ->where(function ($query): void {
                    $query->where('source_provenance', 'SIMULATED')
                        ->orWhere(function ($source): void {
                            $source->whereRaw("LOWER(COALESCE(source, '')) = ?", ['esocial'])
                                ->whereRaw("LOWER(COALESCE(source_version, '')) = ?", ['fake-1']);
                        });
                })
                ->update([
                    'source_provenance' => 'UNVERIFIED',
                    'verification_state' => 'REJECTED',
                ]);
        }

        if (Schema::hasTable('fiscal_guide_stubs')) {
            DB::table('fiscal_guide_stubs')
                ->where(function ($query): void {
                    $query->where('emission_status', 'STUB')
                        ->orWhere('document_number', 'like', 'STUB-%')
                        ->orWhere('is_external_call', false);
                })
                ->update([
                    'is_quarantined' => true,
                    'quarantine_reason' => 'SYNTHETIC_GUIDE_LEGACY',
                    'quarantined_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        foreach (['esocial_event_evidences', 'fgts_competence_statuses', 'fiscal_guide_stubs'] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'is_quarantined')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->dropIndex($table.'_quarantine_idx');
                $blueprint->dropColumn(['is_quarantined', 'quarantine_reason', 'quarantined_at']);
            });
        }
    }

    private function addQuarantineColumns(string $table): void
    {
        if (! Schema::hasTable($table) || Schema::hasColumn($table, 'is_quarantined')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($table): void {
            $blueprint->boolean('is_quarantined')->default(false);
            $blueprint->string('quarantine_reason', 120)->nullable();
            $blueprint->timestampTz('quarantined_at')->nullable();
            $blueprint->index(['office_id', 'is_quarantined'], $table.'_quarantine_idx');
        });
    }
};
