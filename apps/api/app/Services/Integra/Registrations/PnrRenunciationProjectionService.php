<?php

namespace App\Services\Integra\Registrations;

use App\Contracts\SecureObjectStore;
use App\Enums\FiscalSourceProvenance;
use App\Enums\SecureObjectPurpose;
use App\Models\Client;
use App\Models\FiscalPnrRenunciation;
use App\Models\Office;
use App\Services\Integra\ContributorCnpjResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Persiste exclusivamente projeções já recebidas e validadas das leituras PNR.
 *
 * Esta classe não conhece executor ou rota HTTP; portanto não é capaz de
 * solicitar renúncia nem de realizar egress. O adaptador da task 2.2 deverá
 * entregar aqui apenas a resposta da operação de leitura explicitamente aceita.
 */
final class PnrRenunciationProjectionService
{
    public const HISTORY_OPERATION_KEY = 'pnr_contador.consultar_renuncias';

    public const STATUS_OPERATION_KEY = 'pnr_contador.situacao_renuncia';

    public const RECEIPT_OPERATION_KEY = 'pnr_contador.emitir_comprovante';

    public function __construct(
        private readonly ContributorCnpjResolver $contributors,
        private readonly PnrRenunciationsResponseCodec $renunciations,
        private readonly PnrRenunciationReceiptCodec $receipts,
        private readonly SecureObjectStore $vault,
    ) {}

    /**
     * @return list<FiscalPnrRenunciation>
     */
    public function projectHistory(Office $office, Client $client, string $sourceProvenance, mixed $dados): array
    {
        $this->assertClientBelongsToOffice($office, $client);
        $provenance = $this->requireOfficialSource($sourceProvenance);
        $page = $this->renunciations->decodeHistory($dados);
        $contributor = $this->contributors->resolve($client);
        $evidence = $this->evidenceVersion($page);
        $now = now();

        foreach ($page['rows'] as $row) {
            $this->assertContributorMatches($contributor, $row['contributor_cnpj']);
        }

        return DB::transaction(function () use ($page, $office, $client, $provenance, $contributor, $evidence, $now): array {
            $projections = [];
            foreach ($page['rows'] as $row) {
                $projections[] = $this->upsertRenunciation(
                    office: $office,
                    client: $client,
                    contributor: $contributor,
                    renunciationId: $row['id'],
                    attributes: [
                        'status' => $row['status'],
                        'history_evidence_version' => $evidence,
                        'source_provenance' => $provenance->value,
                        'summary_sanitized' => [
                            'history_page' => $page['page'],
                            'history_total' => $page['total'],
                            'history_last_page' => $page['last'],
                        ],
                        'occurred_at' => $this->timestampFromMillis($row['occurred_at']),
                        'observed_at' => $now,
                        'refreshed_at' => $now,
                    ],
                );
            }

            return $projections;
        });
    }

    public function projectStatus(Office $office, Client $client, string $sourceProvenance, mixed $dados): ?FiscalPnrRenunciation
    {
        $this->assertClientBelongsToOffice($office, $client);
        $provenance = $this->requireOfficialSource($sourceProvenance);
        $status = $this->renunciations->decodeStatus($dados);
        if ($status['renunciation'] === null) {
            return null;
        }

        $contributor = $this->contributors->resolve($client);
        $evidence = $this->evidenceVersion($status);
        $now = now();
        $renunciation = $status['renunciation'];
        $this->assertContributorMatches($contributor, $renunciation['contributor_cnpj']);

        return DB::transaction(fn (): FiscalPnrRenunciation => $this->upsertRenunciation(
            office: $office,
            client: $client,
            contributor: $contributor,
            renunciationId: $renunciation['id'],
            attributes: [
                'status' => $renunciation['status'],
                'status_evidence_version' => $evidence,
                'source_provenance' => $provenance->value,
                'summary_sanitized' => [
                    'status_approved' => $status['approved'],
                    'status_has_renunciation' => true,
                ],
                'occurred_at' => $this->timestampFromMillis($renunciation['occurred_at']),
                'observed_at' => $now,
                'refreshed_at' => $now,
            ],
        ));
    }

    public function projectReceipt(
        Office $office,
        Client $client,
        int $renunciationId,
        string $sourceProvenance,
        mixed $dados,
    ): FiscalPnrRenunciation {
        $this->assertClientBelongsToOffice($office, $client);
        if ($renunciationId < 1) {
            throw new RuntimeException('Identificador da renúncia deve ser positivo.');
        }
        $provenance = $this->requireOfficialSource($sourceProvenance);
        $receipt = $this->receipts->decode($dados);
        $contributor = $this->contributors->resolve($client);
        $now = now();

        return DB::transaction(function () use ($office, $client, $renunciationId, $provenance, $receipt, $contributor, $now): FiscalPnrRenunciation {
            $existing = FiscalPnrRenunciation::query()
                ->withoutGlobalScopes()
                ->where('office_id', $office->id)
                ->where('client_id', $client->id)
                ->where('renunciation_id', $renunciationId)
                ->lockForUpdate()
                ->first();
            if ($existing === null || $existing->contributor_cnpj !== $contributor) {
                throw new RuntimeException('Comprovante PNR exige renúncia previamente validada para o contribuinte.');
            }

            $maxBytes = (int) config('fiscal_monitoring.evidence.max_bytes', 5_242_880);
            if (strlen($receipt['contents']) > $maxBytes) {
                throw new RuntimeException("Comprovante PNR excede limite de {$maxBytes} bytes.");
            }

            $objectId = $existing?->receipt_sha256 === $receipt['sha256']
                ? $existing->receipt_vault_object_id
                : $this->vault->put($receipt['contents'], self::receiptAad($office->id, $client->id, $renunciationId, $receipt['sha256']));

            return $this->upsertRenunciation(
                office: $office,
                client: $client,
                contributor: $contributor,
                renunciationId: $renunciationId,
                attributes: [
                    'source_provenance' => $provenance->value,
                    'summary_sanitized' => [
                        'has_receipt' => true,
                    ],
                    'receipt_vault_object_id' => $objectId,
                    'receipt_sha256' => $receipt['sha256'],
                    'receipt_mime_type' => $receipt['mime_type'],
                    'receipt_byte_size' => strlen($receipt['contents']),
                    'receipt_observed_at' => $now,
                    'observed_at' => $now,
                    'refreshed_at' => $now,
                ],
            );
        });
    }

    /** @return array<string, scalar> */
    public static function receiptAad(int $officeId, int $clientId, int $renunciationId, string $sha256): array
    {
        return SecureObjectPurpose::FiscalEvidence->aadBase([
            'office_id' => $officeId,
            'client_id' => $clientId,
            'renunciation_id' => $renunciationId,
            'sha256' => $sha256,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function upsertRenunciation(
        Office $office,
        Client $client,
        string $contributor,
        int $renunciationId,
        array $attributes,
    ): FiscalPnrRenunciation {
        return FiscalPnrRenunciation::query()
            ->withoutGlobalScopes()
            ->updateOrCreate(
                [
                    'office_id' => $office->id,
                    'client_id' => $client->id,
                    'renunciation_id' => $renunciationId,
                ],
                array_merge([
                    'contributor_cnpj' => $contributor,
                    'status' => 'UNKNOWN',
                ], $attributes),
            );
    }

    private function assertClientBelongsToOffice(Office $office, Client $client): void
    {
        if ((int) $client->office_id !== (int) $office->id) {
            throw new RuntimeException('Cliente não pertence ao escritório ativo.');
        }
    }

    private function requireOfficialSource(string $sourceProvenance): FiscalSourceProvenance
    {
        $provenance = FiscalSourceProvenance::tryFrom($sourceProvenance);
        if (! in_array($provenance, [FiscalSourceProvenance::SerproTrial, FiscalSourceProvenance::SerproReal], true)) {
            throw new RuntimeException('Fonte PNR não verificável não pode gerar projeção.');
        }

        return $provenance;
    }

    private function assertContributorMatches(string $expected, string $returned): void
    {
        if (! hash_equals($expected, $returned)) {
            throw new RuntimeException('CNPJ retornado pela renúncia não pertence ao contribuinte consultado.');
        }
    }

    /** @param array<string, mixed> $evidence */
    private function evidenceVersion(array $evidence): string
    {
        return hash('sha256', json_encode($evidence, JSON_THROW_ON_ERROR));
    }

    private function timestampFromMillis(?int $milliseconds): ?CarbonImmutable
    {
        return $milliseconds === null
            ? null
            : CarbonImmutable::createFromTimestampMs($milliseconds);
    }
}
