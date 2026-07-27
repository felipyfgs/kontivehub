<?php

namespace App\Services\Fiscal\Guides;

use App\Contracts\SecureObjectStore;
use App\DTO\Fiscal\Guides\PagtowebArrecadacaoReceiptDto;
use App\Enums\FiscalSourceProvenance;
use App\Enums\SecureObjectPurpose;
use App\Models\Client;
use App\Models\PagtowebArrecadacaoReceipt;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Projeta exclusivamente comprovantes oficiais validados pelo codec 7.2. */
final class PagtowebArrecadacaoReceiptProjector
{
    public function __construct(private readonly SecureObjectStore $vault) {}

    public function project(Tenant $tenant, Client $client, string $sourceProvenance, mixed $dados): PagtowebArrecadacaoReceipt
    {
        if ((int) $client->tenant_id !== (int) $tenant->id) {
            throw new RuntimeException('Cliente não pertence ao escritório ativo.');
        }
        $provenance = FiscalSourceProvenance::tryFrom($sourceProvenance);
        if (! in_array($provenance, [FiscalSourceProvenance::SerproTrial, FiscalSourceProvenance::SerproReal], true)) {
            throw new RuntimeException('Fonte PAGTOWEB não verificável não pode gerar comprovante.');
        }

        $receipt = PagtowebArrecadacaoReceiptDto::fromDados($dados);

        return DB::transaction(function () use ($tenant, $client, $provenance, $receipt): PagtowebArrecadacaoReceipt {
            $existing = PagtowebArrecadacaoReceipt::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('client_id', $client->id)
                ->where('receipt_sha256', $receipt->sha256)
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                return $existing;
            }

            $objectId = $this->vault->put(
                $receipt->contents,
                self::receiptAad((int) $tenant->id, (int) $client->id, $receipt->sha256),
            );

            return PagtowebArrecadacaoReceipt::query()->create([
                'tenant_id' => $tenant->id,
                'client_id' => $client->id,
                'receipt_vault_object_id' => $objectId,
                'receipt_sha256' => $receipt->sha256,
                'receipt_mime_type' => $receipt->mimeType,
                'receipt_byte_size' => strlen($receipt->contents),
                'source_provenance' => $provenance->value,
                'observed_at' => now(),
            ]);
        });
    }

    /** @return array<string, scalar> */
    public static function receiptAad(int $tenantId, int $clientId, string $sha256): array
    {
        return SecureObjectPurpose::FiscalEvidence->aadBase([
            'tenant_id' => $tenantId,
            'client_id' => $clientId,
            'operation_key' => 'pagtoweb.comparrecadacao',
            'sha256' => $sha256,
        ]);
    }

    public function readAuthorized(PagtowebArrecadacaoReceipt $receipt, int $tenantId): string
    {
        if ((int) $receipt->tenant_id !== $tenantId) {
            throw new RuntimeException('Comprovante não pertence ao escritório ativo.');
        }

        return $this->vault->get(
            $receipt->receipt_vault_object_id,
            self::receiptAad($tenantId, (int) $receipt->client_id, $receipt->receipt_sha256),
        );
    }
}
