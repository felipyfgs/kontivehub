<?php

namespace App\Console\Commands;

use App\Enums\OutboundFiscalModel;
use App\Enums\OutboundNumberStatus;
use App\Enums\OutboundProfileStatus;
use App\Models\OutboundCaptureProfile;
use App\Models\OutboundNumberState;
use App\Services\Outbound\OutboundXmlRecoveryOrchestrator;
use App\Services\Outbound\SvrsNfceConfig;
use App\Services\Outbound\SvrsNfceKillSwitchService;
use Illuminate\Console\Command;

/**
 * Scheduler de auto-queue SVRS com lote limitado e dependência da flag própria.
 */
class DispatchSvrsNfceXmlRecoveriesCommand extends Command
{
    protected $signature = 'sefaz:dispatch-svrs-nfce-xml-recoveries {--limit=}';

    protected $description = 'Enfileira recuperações SVRS de NFC-e 65 elegíveis (auto-queue).';

    public function handle(
        SvrsNfceConfig $config,
        SvrsNfceKillSwitchService $killSwitch,
        OutboundXmlRecoveryOrchestrator $orchestrator,
    ): int {
        if (! $config->retrievalEnabled() || ! $config->autoQueueEnabled()) {
            $this->info('Auto-queue SVRS desligado.');

            return self::SUCCESS;
        }
        if ($killSwitch->isActive()) {
            $this->warn('Kill switch SVRS ativo.');

            return self::SUCCESS;
        }

        $limit = (int) ($this->option('limit') ?: $config->maxKeysPerRun());
        $limit = max(1, min($limit, $config->maxKeysPerRun()));

        $profiles = OutboundCaptureProfile::query()
            ->where('status', OutboundProfileStatus::Active)
            ->where('model', OutboundFiscalModel::Nfce)
            ->where('uf', 'MA')
            ->when($config->pilotAllowlistOnly(), fn ($q) => $q->where('allowlisted', true))
            ->where('kill_switch', false)
            ->orderBy('id')
            ->get();

        $queued = 0;
        foreach ($profiles as $profile) {
            if ($queued >= $limit) {
                break;
            }

            // Spread determinístico no minuto (evita todos os perfis no mesmo tick)
            $minute = (int) now()->format('i');
            if ($profiles->count() > 5 && ($profile->id % 60) !== $minute) {
                continue;
            }

            $numbers = OutboundNumberState::query()
                ->where('outbound_capture_profile_id', $profile->id)
                ->whereIn('status', [
                    OutboundNumberStatus::KeyDiscovered,
                    OutboundNumberStatus::XmlPending,
                ])
                ->whereNotNull('discovered_access_key')
                ->orderBy('id')
                ->limit($limit - $queued)
                ->get();

            foreach ($numbers as $number) {
                if ($queued >= $limit) {
                    break 2;
                }
                // ensureRecovery + enqueue idempotente (não re-dispatch QUEUED/RUNNING)
                $req = $orchestrator->ensureRecovery($number, $profile, queue: true, triggeredBy: 'scheduler');
                if ($req !== null) {
                    $queued++;
                }
            }
        }

        $this->info("Enfileiradas: {$queued}");

        return self::SUCCESS;
    }
}
