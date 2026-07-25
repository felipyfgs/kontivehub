<?php

namespace App\Services\Sefaz;

use App\Contracts\SefazNfeManifestationClient;
use App\Enums\CaptureChannel;
use App\Enums\CredentialStatus;
use App\Enums\NfeManifestationType;
use App\Exceptions\Adn\AdnPermanentException;
use App\Jobs\ReconsultNfeAfterManifestationJob;
use App\Models\ClientCredential;
use App\Models\DocumentInterest;
use App\Models\Establishment;
use App\Models\NfeDocument;
use App\Models\NfeEvent;
use App\Services\Certificates\CredentialService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Orquestra MD-e (ciência / conclusivas) + enfileira reconsulta DistDFe para procNFe.
 */
final class NfeManifestationService
{
    public function __construct(
        private readonly SefazNfeManifestationClient $client,
        private readonly CredentialService $credentials,
    ) {}

    /**
     * @return array{
     *   status: string,
     *   has_full_xml: bool,
     *   message: string,
     *   c_stat?: string|null,
     *   x_motivo?: string|null,
     *   protocol?: string|null,
     *   manifestation_status?: string|null,
     *   type?: string|null
     * }
     */
    public function manifest(
        string $accessKey,
        int $officeId,
        NfeManifestationType $type,
        ?string $justification = null,
        string $purpose = 'UNLOCK_XML',
    ): array {
        if (! $this->isAllowed($type, $purpose)) {
            $flagHint = $type->isConclusive()
                ? 'SEFAZ_MANIFEST_ENABLED'
                : 'SEFAZ_AUTO_CIENCIA_ENABLED ou SEFAZ_MANIFEST_ENABLED';

            return [
                'status' => 'flag_off',
                'has_full_xml' => $this->hasFull($officeId, $accessKey),
                'message' => "Manifestação SEFAZ desabilitada ({$flagHint}).",
                'type' => $type->value,
            ];
        }

        $hasFull = $this->hasFull($officeId, $accessKey);

        // Ciência só para desbloqueio: se full já existe, no-op
        if ($type === NfeManifestationType::Ciencia && $hasFull && $purpose === 'UNLOCK_XML') {
            return [
                'status' => 'already_full',
                'has_full_xml' => true,
                'message' => 'XML completo já disponível; ciência não necessária.',
                'type' => $type->value,
            ];
        }

        $nfe = NfeDocument::query()
            ->where('office_id', $officeId)
            ->where('access_key', $accessKey)
            ->orderBy('is_summary') // full first
            ->first();

        if ($nfe === null) {
            return [
                'status' => 'not_found',
                'has_full_xml' => false,
                'message' => 'Documento NF-e não encontrado neste escritório.',
                'type' => $type->value,
            ];
        }

        // Bloquear ciência após conclusiva (regra 655)
        $current = (string) ($nfe->manifestation_status ?? '');
        if ($type === NfeManifestationType::Ciencia && in_array($current, ['CONFIRMADA', 'DESCONHECIDA', 'NAO_REALIZADA'], true)) {
            return [
                'status' => 'rejected_local',
                'has_full_xml' => $hasFull,
                'message' => 'Ciência não é permitida após manifestação conclusiva.',
                'manifestation_status' => $current,
                'type' => $type->value,
            ];
        }

        if ($type->requiresJustification()) {
            $just = trim((string) $justification);
            $len = mb_strlen($just);
            if ($len < 15 || $len > 255) {
                return [
                    'status' => 'validation_error',
                    'has_full_xml' => $hasFull,
                    'message' => 'Justificativa obrigatória com 15 a 255 caracteres para operação não realizada.',
                    'type' => $type->value,
                ];
            }
        }

        $ctx = $this->resolveAuthorContext($officeId, $accessKey, $nfe);
        if ($ctx === null) {
            return [
                'status' => 'no_credential',
                'has_full_xml' => $hasFull,
                'message' => 'Não foi possível resolver estabelecimento/A1 do destinatário para esta chave.',
                'type' => $type->value,
            ];
        }

        try {
            $result = $this->client->register(
                $ctx['certificate'],
                $ctx['cnpj'],
                $accessKey,
                $type,
                $justification,
            );
        } catch (AdnPermanentException $e) {
            return [
                'status' => 'error',
                'has_full_xml' => $hasFull,
                'message' => $e->getMessage(),
                'type' => $type->value,
            ];
        } catch (Throwable $e) {
            Log::warning('sefaz.manifest.failed', [
                'access_key' => $accessKey,
                'type' => $type->value,
                'error' => mb_substr($e->getMessage(), 0, 200),
            ]);

            return [
                'status' => 'error',
                'has_full_xml' => $hasFull,
                'message' => 'Falha ao comunicar com a SEFAZ para manifestação.',
                'type' => $type->value,
            ];
        }

        if (! $result->isAccepted()) {
            return [
                'status' => 'rejected_sefaz',
                'has_full_xml' => $hasFull,
                'message' => $result->effectiveMotivo(),
                'c_stat' => $result->effectiveCStat(),
                'x_motivo' => $result->effectiveMotivo(),
                'type' => $type->value,
            ];
        }

        $statusValue = $type->manifestationStatus();
        NfeDocument::query()
            ->where('office_id', $officeId)
            ->where('access_key', $accessKey)
            ->update(['manifestation_status' => $statusValue]);

        NfeEvent::query()->create([
            'office_id' => $officeId,
            'dfe_document_id' => $nfe->dfe_document_id,
            'access_key' => $accessKey,
            'event_type' => $type->tpEvento(),
            'sequence' => 1,
            'event_at' => now(),
            'status' => 'REGISTERED',
            'protocol' => $result->protocol,
        ]);

        // Reconsulta assíncrona após ciência (ou qualquer sucesso se ainda só resumo)
        if ($type === NfeManifestationType::Ciencia || ! $hasFull) {
            ReconsultNfeAfterManifestationJob::dispatch(
                $officeId,
                $accessKey,
                $ctx['establishment_id'],
            );
        }

        return [
            'status' => 'accepted',
            'has_full_xml' => $hasFull,
            'message' => $type === NfeManifestationType::Ciencia
                ? 'Ciência registrada. Reconsulta DistDFe enfileirada para obter o XML completo.'
                : 'Manifestação registrada na SEFAZ.',
            'c_stat' => $result->effectiveCStat(),
            'x_motivo' => $result->effectiveMotivo(),
            'protocol' => $result->protocol,
            'manifestation_status' => $statusValue,
            'type' => $type->value,
        ];
    }

    private function hasFull(int $officeId, string $accessKey): bool
    {
        return NfeDocument::query()
            ->where('office_id', $officeId)
            ->where('access_key', $accessKey)
            ->where('is_summary', false)
            ->exists();
    }

    /**
     * Conclusivas: só SEFAZ_MANIFEST_ENABLED.
     * Ciência (unlock/auto): AUTO_CIENCIA ou MANIFEST.
     */
    private function isAllowed(NfeManifestationType $type, string $purpose): bool
    {
        if ($type->isConclusive()) {
            return (bool) config('sefaz.manifest_enabled', false);
        }

        if (config('sefaz.manifest_enabled', false)) {
            return true;
        }

        if (! config('sefaz.auto_ciencia_enabled', false)) {
            return false;
        }

        // Ciência automática ou unlock técnico (UI) — nunca conclusiva por este caminho.
        return in_array($purpose, ['UNLOCK_XML', 'AUTO_UNLOCK'], true);
    }

    /**
     * @return array{certificate: array{pfx: string, password: string}, cnpj: string, establishment_id: int}|null
     */
    private function resolveAuthorContext(int $officeId, string $accessKey, NfeDocument $nfe): ?array
    {
        $interest = DocumentInterest::query()
            ->where('office_id', $officeId)
            ->where('channel', CaptureChannel::NfeDistDfe->value)
            ->where('dfe_document_id', $nfe->dfe_document_id)
            ->with(['establishment.client'])
            ->first();

        $establishment = $interest?->establishment;

        if ($establishment === null && $nfe->recipient_cnpj) {
            $establishment = Establishment::query()
                ->where('office_id', $officeId)
                ->where('cnpj', strtoupper($nfe->recipient_cnpj))
                ->with('client')
                ->first();
        }

        if ($establishment === null) {
            // Fallback: qualquer interest da chave via dfe com mesmo access_key
            $interest = DocumentInterest::query()
                ->where('office_id', $officeId)
                ->where('channel', CaptureChannel::NfeDistDfe->value)
                ->whereHas('document', fn ($q) => $q->where('access_key', $accessKey))
                ->with(['establishment.client'])
                ->first();
            $establishment = $interest?->establishment;
        }

        if ($establishment === null || $establishment->client === null) {
            return null;
        }

        $credential = ClientCredential::query()
            ->where('client_id', $establishment->client_id)
            ->where('status', CredentialStatus::Active)
            ->first();

        if ($credential === null) {
            return null;
        }

        $material = $this->credentials->loadPfxMaterial($credential);
        if ($material === null) {
            return null;
        }

        return [
            'certificate' => $material,
            'cnpj' => $establishment->cnpj,
            'establishment_id' => (int) $establishment->id,
        ];
    }
}
