<?php

namespace App\Console\Commands;

use App\Enums\CaptureChannel;
use App\Models\ChannelSyncCursor;
use App\Models\CteCoverageSnapshot;
use App\Models\TenantDistributionCursor;
use Illuminate\Console\Command;

/**
 * Inspeção somente-leitura de cursores e cobertura CT-e.
 * Sem XML, PFX, vault_object_id ou material fiscal bruto.
 */
class SefazCteInspectCommand extends Command
{
    protected $signature = 'sefaz:cte-inspect
                            {--tenant= : Filtra por tenant_id}
                            {--json : Saída JSON sanitizada}';

    protected $description = 'Lista cursores e cobertura CT-e sem material fiscal bruto';

    public function handle(): int
    {
        $tenantId = $this->option('tenant');
        $tenantFilter = $tenantId !== null && $tenantId !== '' ? (int) $tenantId : null;

        $clientCursors = ChannelSyncCursor::query()
            ->where('channel', CaptureChannel::CteDistDfe->value)
            ->when($tenantFilter !== null, fn ($q) => $q->where('tenant_id', $tenantFilter))
            ->with('establishment:id,client_id,cnpj')
            ->orderBy('id')
            ->get()
            ->map(fn (ChannelSyncCursor $c) => [
                'id' => $c->id,
                'tenant_id' => $c->tenant_id,
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

        $tenantCursors = TenantDistributionCursor::query()
            ->where('channel', CaptureChannel::CteAutXmlDistDfe->value)
            ->when($tenantFilter !== null, fn ($q) => $q->where('tenant_id', $tenantFilter))
            ->orderBy('id')
            ->get()
            ->map(fn (TenantDistributionCursor $c) => [
                'id' => $c->id,
                'tenant_id' => $c->tenant_id,
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
            ->when($tenantFilter !== null, fn ($q) => $q->where('tenant_id', $tenantFilter))
            ->orderByDesc('period')
            ->limit(50)
            ->get()
            ->map(fn (CteCoverageSnapshot $s) => [
                'tenant_id' => $s->tenant_id,
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
            'tenant_streams' => $tenantCursors,
            'coverage' => $coverage,
            'summary' => [
                'client_streams' => count($clientCursors),
                'tenant_streams' => count($tenantCursors),
                'coverage_rows' => count($coverage),
            ],
        ];

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info('CT-e client streams: '.count($clientCursors));
        $this->table(
            ['id', 'tenant', 'est', 'status', 'last_nsu', 'cStat', 'retry'],
            collect($clientCursors)->map(fn (array $r) => [
                $r['id'], $r['tenant_id'], $r['establishment_id'], $r['status'],
                $r['last_nsu'], $r['last_cstat'] ?? '—', $r['retry_allowed'] ? 'yes' : 'no',
            ])->all()
        );

        $this->info('CT-e autXML tenant streams: '.count($tenantCursors));
        $this->table(
            ['id', 'tenant', 'status', 'last_nsu', 'cStat', 'consumer'],
            collect($tenantCursors)->map(fn (array $r) => [
                $r['id'], $r['tenant_id'], $r['status'], $r['last_nsu'],
                $r['last_cstat'] ?? '—', $r['external_consumer_status'] ?? '—',
            ])->all()
        );

        $this->info('Coverage snapshots (até 50): '.count($coverage));

        return self::SUCCESS;
    }
}
