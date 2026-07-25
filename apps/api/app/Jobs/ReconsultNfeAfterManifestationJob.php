<?php

namespace App\Jobs;

use App\Contracts\SefazDistDfeClient;
use App\Enums\CaptureChannel;
use App\Enums\CredentialStatus;
use App\Enums\SyncCursorStatus;
use App\Models\ChannelSyncCursor;
use App\Models\ClientCredential;
use App\Models\Establishment;
use App\Models\NfeDocument;
use App\Services\Certificates\CredentialService;
use App\Services\Sefaz\DistDfePageProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Após MD-e (ciência), reconsulta DistDFe por chave (consChNFe) sem avançar NSU do cursor.
 */
class ReconsultNfeAfterManifestationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public int $officeId,
        public string $accessKey,
        public int $establishmentId,
    ) {
        $this->onQueue((string) config('sefaz.queues.manifest', 'manifest-nfe'));
    }

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(
        SefazDistDfeClient $client,
        DistDfePageProcessor $processor,
        CredentialService $credentials,
    ): void {
        if (! config('sefaz.manifest_enabled') && ! config('sefaz.distdfe_enabled')) {
            return;
        }

        // Já tem full?
        if (NfeDocument::query()
            ->where('office_id', $this->officeId)
            ->where('access_key', $this->accessKey)
            ->where('is_summary', false)
            ->exists()
        ) {
            return;
        }

        $establishment = Establishment::query()
            ->with('client')
            ->find($this->establishmentId);

        if ($establishment === null || $establishment->client === null) {
            return;
        }

        $credential = ClientCredential::query()
            ->where('client_id', $establishment->client_id)
            ->where('status', CredentialStatus::Active)
            ->first();

        if ($credential === null) {
            return;
        }

        $material = $credentials->loadPfxMaterial($credential);
        if ($material === null) {
            return;
        }

        $cUf = $this->resolveUfAutor($establishment);

        try {
            $page = $client->distByAccessKey(
                $material,
                $establishment->cnpj,
                $this->accessKey,
                $cUf,
            );
        } catch (Throwable $e) {
            Log::warning('sefaz.manifest.reconsult.failed', [
                'access_key' => $this->accessKey,
                'error' => mb_substr($e->getMessage(), 0, 200),
            ]);
            throw $e;
        }

        if ($page->isAbuse()) {
            Log::warning('sefaz.manifest.reconsult.abuse', [
                'access_key' => $this->accessKey,
                'c_stat' => $page->cStat,
            ]);

            return;
        }

        if ($page->documents === []) {
            Log::info('sefaz.manifest.reconsult.empty', [
                'access_key' => $this->accessKey,
                'c_stat' => $page->cStat,
            ]);

            return;
        }

        $env = (string) config('sefaz.environment', 'production');
        $cursor = ChannelSyncCursor::query()->firstOrCreate(
            [
                'establishment_id' => $this->establishmentId,
                'environment' => $env,
                'source' => CaptureChannel::NfeDistDfe->source(),
                'channel' => CaptureChannel::NfeDistDfe->value,
            ],
            [
                'office_id' => $this->officeId,
                'last_nsu' => 0,
                'status' => SyncCursorStatus::Idle,
            ]
        );

        $processor->ingestDocuments($cursor, $establishment, $page);

        $hasFull = NfeDocument::query()
            ->where('office_id', $this->officeId)
            ->where('access_key', $this->accessKey)
            ->where('is_summary', false)
            ->exists();

        Log::info('sefaz.manifest.reconsult.done', [
            'access_key' => $this->accessKey,
            'has_full' => $hasFull,
            'docs' => count($page->documents),
        ]);
    }

    private function resolveUfAutor(Establishment $establishment): string
    {
        $state = $establishment->address_state ?? null;
        $map = [
            'AC' => '12', 'AL' => '27', 'AP' => '16', 'AM' => '13', 'BA' => '29',
            'CE' => '23', 'DF' => '53', 'ES' => '32', 'GO' => '52', 'MA' => '21',
            'MT' => '51', 'MS' => '50', 'MG' => '31', 'PA' => '15', 'PB' => '25',
            'PR' => '41', 'PE' => '26', 'PI' => '22', 'RJ' => '33', 'RN' => '24',
            'RS' => '43', 'RO' => '11', 'RR' => '14', 'SC' => '42', 'SP' => '35',
            'SE' => '28', 'TO' => '17',
        ];
        if (is_string($state) && isset($map[strtoupper($state)])) {
            return $map[strtoupper($state)];
        }

        return (string) config('sefaz.default_cuf_autor', '35');
    }
}
