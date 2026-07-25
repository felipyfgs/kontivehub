<?php

namespace App\Services\Fiscal\SimplesMei;

use App\Contracts\SecureObjectStore;
use App\Contracts\SerproOperationExecutor;
use App\Models\CcmeiIssuedCertificate;
use App\Models\Client;
use App\Models\Office;
use RuntimeException;

/** Histórico local, emissão manual confirmada e leitura autorizada de CCMEI121. */
final class CcmeiCertificateIssuanceService
{
    public const OPERATION_KEY = 'ccmei.emitirccmei';

    public function __construct(
        private readonly SerproOperationExecutor $operations,
        private readonly CcmeiCertificateIssuanceProjector $projector,
        private readonly SecureObjectStore $vault,
    ) {}

    /** @return array<string, mixed> */
    public function history(Office $office, Client $client): array
    {
        $this->assertClient($office, $client);
        $certificates = CcmeiIssuedCertificate::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->where('client_id', $client->id)
            ->latest('observed_at')
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(static fn (CcmeiIssuedCertificate $certificate): array => $certificate->toPublicArray())
            ->values()
            ->all();

        return [
            'client_id' => $client->id,
            'certificates' => $certificates,
            'provenance' => ['source' => 'local_projection', 'serpro_called' => false],
        ];
    }

    /** @return array{success:bool,certificate?:array<string,mixed>,error_code?:string|null,error_message?:string|null} */
    public function issue(Office $office, Client $client, ?string $correlationId = null): array
    {
        $this->assertClient($office, $client);
        $this->assertActiveClient($office, $client);
        $response = $this->operations->execute(
            office: $office,
            client: $client,
            operationKey: self::OPERATION_KEY,
            businessData: [],
            idempotencyKey: sprintf('ccmei:issue:%d:%d', $office->id, $client->id),
            correlationId: $correlationId,
            module: 'simples_mei',
        );
        if (! $response->success) {
            return $this->failure($response->errorCode, $response->errorMessage);
        }
        if ($response->hasSimulatedSource()) {
            return $this->failure('SIMULATED_SOURCE_REJECTED', 'Fonte sintética não pode emitir certificado CCMEI.');
        }

        try {
            $certificate = $this->projector->project($office, $client, (string) $response->sourceProvenance, $response->dados);
        } catch (RuntimeException $exception) {
            return $this->failure('RESPONSE_LAYOUT_INVALID', $exception->getMessage());
        }

        return ['success' => true, 'certificate' => $certificate->toPublicArray()];
    }

    public function findForDownload(Office $office, Client $client, int $certificateId): ?CcmeiIssuedCertificate
    {
        $this->assertClient($office, $client);

        return CcmeiIssuedCertificate::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->where('client_id', $client->id)
            ->whereKey($certificateId)
            ->first();
    }

    public function read(CcmeiIssuedCertificate $certificate): string
    {
        if ($certificate->certificate_vault_object_id === null || $certificate->certificate_sha256 === null) {
            throw new RuntimeException('Certificado indisponível.');
        }

        return $this->vault->get(
            $certificate->certificate_vault_object_id,
            CcmeiCertificateIssuanceProjector::certificateAad(
                (int) $certificate->office_id,
                (int) $certificate->client_id,
                $certificate->certificate_sha256,
            ),
        );
    }

    private function assertClient(Office $office, Client $client): void
    {
        if ((int) $client->office_id !== (int) $office->id) {
            throw new RuntimeException('Cliente não encontrado no escritório atual.');
        }
    }

    private function assertActiveClient(Office $office, Client $client): void
    {
        $exists = Client::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->whereKey($client->getKey())
            ->exists();

        if (! $exists) {
            throw new RuntimeException('Cliente não encontrado no escritório atual.');
        }
    }

    /** @return array{success:false,error_code:string|null,error_message:string|null} */
    private function failure(?string $code, ?string $message): array
    {
        return ['success' => false, 'error_code' => $code, 'error_message' => $message];
    }
}
