<?php

namespace App\Services\Integra\Dctfweb;

use App\Enums\DctfwebArtifactKind;
use App\Enums\DctfwebCategory;
use App\Enums\DctfwebDeclarationState;
use App\Enums\DctfwebTransmissionStatus;
use App\Enums\FiscalCoverage;
use App\Enums\FiscalPaymentStatus;
use App\Enums\FiscalSituation;
use App\Models\Client;
use App\Models\DctfwebDarfDocument;
use App\Models\DctfwebDeclaration;
use App\Models\DctfwebEvidenceVersion;
use App\Models\FiscalEvidenceArtifact;
use App\Models\FiscalMonitoringRun;
use App\Models\Office;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Projeção de declaração DCTFWeb por contribuinte/competência.
 */
final class DctfwebDeclarationService
{
    public function __construct(
        private readonly DctfwebCompetenceResolver $competences,
        private readonly DctfwebEvidenceVersioningService $versioning,
    ) {}

    public function findOrCreate(
        Office $office,
        Client $client,
        string $periodKey,
        DctfwebCategory|string $category = DctfwebCategory::GeralMensal,
    ): DctfwebDeclaration {
        $periodKey = $this->competences->normalizePeriodKey($periodKey);
        $cat = $category instanceof DctfwebCategory
            ? $category
            : (DctfwebCategory::fromOfficialCode($category) ?? DctfwebCategory::default());
        $competence = $this->competences->resolve($office, $client, $periodKey, DctfwebCodes::CATEGORY_DCTFWEB);

        $existing = DctfwebDeclaration::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->where('client_id', $client->id)
            ->where('period_key', $periodKey)
            ->where('category', $cat->value)
            ->first();

        // Legado: registros sem category (ou default implícito) no mesmo PA.
        if ($existing === null) {
            $existing = DctfwebDeclaration::query()
                ->withoutGlobalScopes()
                ->where('office_id', $office->id)
                ->where('client_id', $client->id)
                ->where('period_key', $periodKey)
                ->where(function ($q) use ($cat): void {
                    $q->whereNull('category')
                        ->orWhere('category', '')
                        ->orWhere('category', $cat->value);
                })
                ->orderBy('id')
                ->first();
        }

        if ($existing !== null) {
            $fill = [];
            if ($existing->competence_id === null) {
                $fill['competence_id'] = $competence->id;
            }
            if ($existing->category === null) {
                $fill['category'] = $cat;
            }
            if ($fill !== []) {
                $existing->forceFill($fill)->save();
            }

            return $existing->fresh();
        }

        return DctfwebDeclaration::query()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'competence_id' => $competence->id,
            'period_key' => $periodKey,
            'category' => $cat,
            'declaration_type' => 'ORIGINAL',
            'transmission_status' => DctfwebTransmissionStatus::Unknown,
            'situation' => FiscalSituation::Unknown,
            'declaration_state' => DctfwebDeclarationState::Unverified,
            'coverage' => FiscalCoverage::Full,
            'payment_status' => FiscalPaymentStatus::Unknown,
            'evidence_version' => 0,
        ]);
    }

    /**
     * Aplica resultado de consulta de recibo/declaração.
     *
     * @param  array<string, mixed>  $body
     * @return array{
     *     declaration: DctfwebDeclaration,
     *     version: ?DctfwebEvidenceVersion,
     *     retification: bool,
     *     artifact: ?FiscalEvidenceArtifact
     * }
     */
    public function projectFromRecibo(
        FiscalMonitoringRun $run,
        Office $office,
        Client $client,
        string $periodKey,
        string $evidenceBytes,
        array $body,
        ?string $sourceVersion = null,
    ): array {
        $declaration = $this->findOrCreate($office, $client, $periodKey);

        $receipt = $this->stringFrom($body, ['recibo', 'numeroRecibo', 'receipt_number', 'numero_recibo']);
        $transmitted = $this->boolFrom($body, ['transmitida', 'transmitted', 'entregue'])
            || $receipt !== null
            || strtoupper((string) ($body['status'] ?? '')) === 'TRANSMITIDA';
        $isRetificadora = $this->boolFrom($body, ['retificadora', 'is_rectification'])
            || strtoupper((string) ($body['tipo'] ?? $body['declaration_type'] ?? '')) === 'RETIFICADORA';
        $officialAt = $this->timeFrom($body, ['dataHoraTransmissao', 'transmitted_at', 'official_at']);

        $kind = DctfwebArtifactKind::Recibo;
        $stored = $this->versioning->storeVersioned(
            run: $run,
            declaration: $declaration,
            kind: $kind,
            bytes: $evidenceBytes,
            contentType: 'application/json',
            sourceVersion: $sourceVersion,
            declarationType: $isRetificadora ? 'RECTIFICADORA' : ($declaration->declaration_type ?? 'ORIGINAL'),
            metadata: [
                'receipt_number' => $receipt,
                'keys' => array_keys($body),
            ],
        );

        $txStatus = DctfwebTransmissionStatus::Unknown;
        $situation = FiscalSituation::Unknown;

        if ($transmitted) {
            $txStatus = $isRetificadora || $stored['retification']
                ? DctfwebTransmissionStatus::Rectified
                : DctfwebTransmissionStatus::Transmitted;
            $situation = FiscalSituation::UpToDate;
        } elseif (strtoupper((string) ($body['status'] ?? '')) === 'PENDENTE') {
            $txStatus = DctfwebTransmissionStatus::Pending;
            $situation = FiscalSituation::Pending;
        }

        $declaration->forceFill([
            'declaration_type' => $isRetificadora ? 'RECTIFICADORA' : $declaration->declaration_type,
            'transmission_status' => $txStatus,
            'situation' => $situation,
            'coverage' => FiscalCoverage::Full,
            'receipt_number' => $receipt ?? $declaration->receipt_number,
            'transmitted_at' => $transmitted ? ($officialAt ?? CarbonImmutable::now()) : $declaration->transmitted_at,
            'official_at' => $officialAt ?? $declaration->official_at,
            // payment_status permanece intocado — recibo ≠ pagamento
        ])->save();

        // Competência acompanha situação da declaração
        if ($declaration->competence_id) {
            $declaration->competence?->forceFill([
                'situation' => $situation,
                'coverage' => FiscalCoverage::Full,
            ])->save();
        }

        return [
            'declaration' => $declaration->fresh(),
            'version' => $stored['version'],
            'retification' => $stored['retification'],
            'artifact' => $stored['artifact'],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{declaration: DctfwebDeclaration, version: DctfwebEvidenceVersion, retification: bool, artifact: FiscalEvidenceArtifact}
     */
    public function projectArtifact(
        FiscalMonitoringRun $run,
        Office $office,
        Client $client,
        string $periodKey,
        DctfwebArtifactKind $kind,
        string $evidenceBytes,
        array $body = [],
        ?string $contentType = null,
        ?string $sourceVersion = null,
    ): array {
        $declaration = $this->findOrCreate($office, $client, $periodKey);
        $stored = $this->versioning->storeVersioned(
            run: $run,
            declaration: $declaration,
            kind: $kind,
            bytes: $evidenceBytes,
            contentType: $contentType,
            sourceVersion: $sourceVersion,
            metadata: ['keys' => array_keys($body)],
        );

        return [
            'declaration' => $declaration->fresh(),
            'version' => $stored['version'],
            'retification' => $stored['retification'],
            'artifact' => $stored['artifact'],
        ];
    }

    /**
     * Emite/registra DARF sem marcar pagamento.
     *
     * @param  array<string, mixed>  $body
     */
    public function projectDarf(
        FiscalMonitoringRun $run,
        Office $office,
        Client $client,
        string $periodKey,
        string $evidenceBytes,
        array $body,
        ?string $sourceVersion = null,
        string $contentType = 'application/json',
    ): DctfwebDarfDocument {
        $declaration = $this->findOrCreate($office, $client, $periodKey);
        $stored = $this->versioning->storeVersioned(
            run: $run,
            declaration: $declaration,
            kind: DctfwebArtifactKind::Darf,
            bytes: $evidenceBytes,
            contentType: $contentType,
            sourceVersion: $sourceVersion,
        );

        $sha = hash('sha256', $evidenceBytes);

        return DB::transaction(function () use ($office, $client, $declaration, $stored, $body, $sha) {
            $existing = DctfwebDarfDocument::query()
                ->withoutGlobalScopes()
                ->where('office_id', $office->id)
                ->where('content_sha256', $sha)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $amount = $body['valor'] ?? $body['amount'] ?? $body['valorTotal'] ?? null;
            $due = $this->timeFrom($body, ['vencimento', 'due_at', 'dataVencimento']);
            $docNumber = $this->stringFrom($body, ['numero', 'document_number', 'numeroDocumento']);

            return DctfwebDarfDocument::query()->create([
                'office_id' => $office->id,
                'client_id' => $client->id,
                'declaration_id' => $declaration->id,
                'competence_id' => $declaration->competence_id,
                'evidence_version_id' => $stored['version']->id,
                'evidence_artifact_id' => $stored['artifact']->id,
                'document_number' => $docNumber,
                'amount' => $amount !== null ? (string) $amount : null,
                'due_at' => $due,
                'issued_at' => CarbonImmutable::now(),
                'payment_status' => FiscalPaymentStatus::Unknown,
                'content_sha256' => $sha,
                'metadata' => [
                    'keys' => array_keys($body),
                ],
            ]);
        });
    }

    /**
     * @return LengthAwarePaginator<int, DctfwebDeclaration>
     */
    public function paginate(Office $office, int $perPage = 50, ?int $clientId = null): LengthAwarePaginator
    {
        $q = DctfwebDeclaration::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->orderByDesc('period_key');

        if ($clientId !== null) {
            $q->where('client_id', $clientId);
        }

        return $q->paginate($perPage);
    }

    public function findForOffice(Office $office, int $id): ?DctfwebDeclaration
    {
        return DctfwebDeclaration::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->whereKey($id)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  list<string>  $keys
     */
    private function stringFrom(array $body, array $keys): ?string
    {
        foreach ($keys as $k) {
            if (isset($body[$k]) && is_scalar($body[$k]) && (string) $body[$k] !== '') {
                return (string) $body[$k];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  list<string>  $keys
     */
    private function boolFrom(array $body, array $keys): bool
    {
        foreach ($keys as $k) {
            if (! array_key_exists($k, $body)) {
                continue;
            }
            $v = $body[$k];
            if (is_bool($v)) {
                return $v;
            }
            if (is_numeric($v)) {
                return (int) $v === 1;
            }
            if (is_string($v)) {
                return in_array(strtoupper($v), ['1', 'TRUE', 'SIM', 'S', 'YES'], true);
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  list<string>  $keys
     */
    private function timeFrom(array $body, array $keys): ?CarbonImmutable
    {
        $raw = $this->stringFrom($body, $keys);
        if ($raw === null) {
            return null;
        }
        try {
            return CarbonImmutable::parse($raw);
        } catch (\Throwable) {
            return null;
        }
    }
}
