<?php

namespace App\Jobs;

use App\Enums\NfeManifestationType;
use App\Models\NfeDocument;
use App\Services\Sefaz\NfeManifestationService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Ciência técnica automática (210210) para resumo sem procNFe.
 * Não confirma a operação fiscal — só habilita entrega do XML completo.
 */
class AutoCienciaNfeJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 90;

    public int $uniqueFor = 600;

    public function __construct(
        public int $officeId,
        public string $accessKey,
    ) {
        $this->onQueue((string) config('sefaz.queues.manifest', 'manifest-nfe'));
    }

    public function uniqueId(): string
    {
        return $this->officeId.':'.$this->accessKey;
    }

    public function backoff(): array
    {
        return [60, 180, 600];
    }

    public function handle(NfeManifestationService $manifestation): void
    {
        if (! config('sefaz.auto_ciencia_enabled') && ! config('sefaz.manifest_enabled')) {
            return;
        }

        $hasFull = NfeDocument::query()
            ->where('office_id', $this->officeId)
            ->where('access_key', $this->accessKey)
            ->where('is_summary', false)
            ->exists();

        if ($hasFull) {
            return;
        }

        $summary = NfeDocument::query()
            ->where('office_id', $this->officeId)
            ->where('access_key', $this->accessKey)
            ->where('is_summary', true)
            ->first();

        if ($summary === null) {
            return;
        }

        $status = (string) ($summary->manifestation_status ?? '');
        if (in_array($status, ['CIENCIA_REGISTRADA', 'CONFIRMADA', 'DESCONHECIDA', 'NAO_REALIZADA'], true)) {
            return;
        }

        $result = $manifestation->manifest(
            $this->accessKey,
            $this->officeId,
            NfeManifestationType::Ciencia,
            purpose: 'AUTO_UNLOCK',
        );

        Log::info('sefaz.auto_ciencia.done', [
            'access_key' => $this->accessKey,
            'office_id' => $this->officeId,
            'status' => $result['status'] ?? null,
            'c_stat' => $result['c_stat'] ?? null,
        ]);
    }
}
