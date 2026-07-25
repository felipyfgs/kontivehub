<?php

namespace App\Console\Commands;

use App\Enums\CaptureChannel;
use App\Models\ChannelSyncCursor;
use App\Models\CteCoverageSnapshot;
use App\Models\OfficeDistributionCursor;
use Illuminate\Console\Command;

/**
 * Inspeção somente-leitura de cursores e cobertura CT-e.
 * Sem XML, PFX, vault_object_id ou material fiscal bruto.
 */
class SefazCteInspectCommand extends Command
{
    protected $signature = 'sefaz:cte-inspect
                            {--office= : Filtra por office_id}
                            {--json : Saída JSON sanitizada}';

    protected $description = 'Lista cursores e cobertura CT-e sem material fiscal bruto';

    public function handle(): int
    {
        $officeId = $this->option('office');
        $officeFilter = $officeId !== null && $officeId !== '' ? (int) $officeId : null;

        $clientCursors = ChannelSyncCursor::query()
            ->where('channel', CaptureChannel::CteDistDfe->value)
            ->when($officeFilter !== null, fn ($q) => $q->where('office_id', $officeFilter))
            ->with('establishment:id,client_id,cnpj')
            ->orderBy('id')
            ->get()
            ->map(fn (ChannelSyncCursor $c) => [
                'id' => $c->id,
                'office_id' => $c->office_id,
                'channel' => CaptureChannel::CteDistDfe->value,
                'establishment_id' => $c->establishment_id,
                'client_id' => $c->establishment?->client_id,
                'status' => $c->status?->value ?? (string) $c->status,
                'last_nsu' => $c->last_nsu,
                'max_nsu_seen' => $c->max_nsu_seen,
                'last_cstat' => $c->last_cstat,
                'next_sync_at' => $c->next_sync_at?->toIso8601String(),
                'last_success_at' => $c->last_success_at?->toIso8601String(),
                'consecutive_decode_failures' => $c->consecutive_decode_failures,
                'retry_allowed' => ($c->status?->value ?? '') !== 'BLOCKED'
                    && ! ($c->next_sync_at?->isFuture() ?? false),
            ])->values()->all();

        $officeCursors = OfficeDistributionCursor::query()
            ->where('channel', CaptureChannel::CteAutXmlDistDfe->value)
            ->when($officeFilter !== null, fn ($q) => $q->where('office_id', $officeFilter))
            ->orderBy('id')
            ->get()
            ->map(fn (OfficeDistributionCursor $c) => [
                'id' => $c->id,
                'office_id' => $c->office_id,
                'channel' => CaptureChannel::CteAutXmlDistDfe->value,
                'status' => $c->status?->value ?? (string) $c->status,
                'last_nsu' => $c->last_nsu,
                'max_nsu_seen' => $c->max_nsu_seen,
                'last_cstat' => $c->last_cstat,
                'external_consumer_status' => $c->external_consumer_status,
                'next_sync_at' => $c->next_sync_at?->toIso8601String(),
                'last_heartbeat_at' => $c->last_heartbeat_at?->toIso8601String(),
            ])->values()->all();

        $coverage = CteCoverageSnapshot::query()
            ->when($officeFilter !== null, fn ($q) => $q->where('office_id', $officeFilter))
            ->orderByDesc('period')
            ->limit(50)
            ->get()
            ->map(fn (CteCoverageSnapshot $s) => [
                'office_id' => $s->office_id,
                'client_id' => $s->client_id,
                'period' => $s->period,
                'status' => $s->status?->value ?? (string) $s->status,
                'documents_count' => $s->documents_count,
                'original_count' => $s->original_count,
                'autxml_redacted_count' => $s->autxml_redacted_count,
                'pending_import_count' => $s->pending_import_count,
            ])->values()->all();

        $payload = [
            'client_streams' => $clientCursors,
            'office_streams' => $officeCursors,
            'coverage' => $coverage,
            'summary' => [
                'client_streams' => count($clientCursors),
                'office_streams' => count($officeCursors),
                'coverage_rows' => count($coverage),
            ],
        ];

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info('CT-e client streams: '.count($clientCursors));
        $this->table(
            ['id', 'office', 'est', 'status', 'last_nsu', 'cStat', 'retry'],
            collect($clientCursors)->map(fn (array $r) => [
                $r['id'], $r['office_id'], $r['establishment_id'], $r['status'],
                $r['last_nsu'], $r['last_cstat'] ?? '—', $r['retry_allowed'] ? 'yes' : 'no',
            ])->all()
        );

        $this->info('CT-e autXML office streams: '.count($officeCursors));
        $this->table(
            ['id', 'office', 'status', 'last_nsu', 'cStat', 'consumer'],
            collect($officeCursors)->map(fn (array $r) => [
                $r['id'], $r['office_id'], $r['status'], $r['last_nsu'],
                $r['last_cstat'] ?? '—', $r['external_consumer_status'] ?? '—',
            ])->all()
        );

        $this->info('Coverage snapshots (até 50): '.count($coverage));

        return self::SUCCESS;
    }
}
