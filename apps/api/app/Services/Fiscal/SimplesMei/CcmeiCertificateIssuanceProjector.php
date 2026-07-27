<?php

namespace App\Services\Fiscal\SimplesMei;

use App\Contracts\SecureObjectStore;
use App\DTO\Fiscal\SimplesMei\CcmeiCertificateIssuanceDto;
use App\Enums\FiscalSourceProvenance;
use App\Enums\SecureObjectPurpose;
use App\Models\CcmeiIssuedCertificate;
use App\Models\Client;
use App\Models\Tenant;
use App\Services\Integra\ContributorCnpjResolver;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Projeta somente certificados CCMEI oficiais já validados pelo serviço de domínio. */
final class CcmeiCertificateIssuanceProjector
{
    public function __construct(
        private readonly ContributorCnpjResolver $contributors,
        private readonly SecureObjectStore $vault,
    ) {}

    public function project(Tenant $tenant, Client $client, string $sourceProvenance, mixed $dados): CcmeiIssuedCertificate
    {
        if ((int) $client->tenant_id !== (int) $tenant->id) {
            throw new RuntimeException('Cliente não pertence ao escritório ativo.');
        }
        $provenance = FiscalSourceProvenance::tryFrom($sourceProvenance);
        if (! in_array($provenance, [FiscalSourceProvenance::SerproTrial, FiscalSourceProvenance::SerproReal], true)) {
            throw new RuntimeException('Fonte CCMEI não verificável não pode gerar certificado.');
        }

        $certificate = CcmeiCertificateIssuanceDto::fromDados($dados);
        $contributor = $this->contributors->resolve($client);
        if (! hash_equals($contributor, $certificate->contributorCnpj)) {
            throw new RuntimeException('CNPJ retornado pelo certificado não pertence ao contribuinte consultado.');
        }

        return DB::transaction(function () use ($tenant, $client, $provenance, $certificate, $contributor): CcmeiIssuedCertificate {
            $existing = CcmeiIssuedCertificate::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('client_id', $client->id)
                ->where('certificate_sha256', $certificate->sha256)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $objectId = $this->vault->put(
                $certificate->contents,
                self::certificateAad($tenant->id, $client->id, $certificate->sha256),
            );

            return CcmeiIssuedCertificate::query()->create([
                'tenant_id' => $tenant->id,
                'client_id' => $client->id,
                'contributor_cnpj' => $contributor,
                'certificate_vault_object_id' => $objectId,
                'certificate_sha256' => $certificate->sha256,
                'certificate_mime_type' => $certificate->mimeType,
                'certificate_byte_size' => strlen($certificate->contents),
                'source_provenance' => $provenance->value,
                'observed_at' => now(),
            ]);
        });
    }

    /** @return array<string, scalar> */
    public static function certificateAad(int $tenantId, int $clientId, string $sha256): array
    {
        return SecureObjectPurpose::FiscalEvidence->aadBase([
            'tenant_id' => $tenantId,
            'client_id' => $clientId,
            'operation_key' => 'ccmei.emitirccmei',
            'sha256' => $sha256,
        ]);
    }
}
