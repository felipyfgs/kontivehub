<?php

namespace App\Services\Serpro;

use App\Enums\SerproEnvironment;
use App\Enums\SerproExternalGateKind;
use App\Enums\SerproExternalGateStatus;
use App\Models\SerproExternalGate;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Gates documentais externos (chamados SERPRO, jurídico, ops).
 * Production: seis gates baseline sem waiver nem PDF.
 */
final class SerproExternalGateService
{
    /** @var list<SerproExternalGateKind> */
    public const BASELINE_KINDS = [
        SerproExternalGateKind::OauthEndpointDivergence,
        SerproExternalGateKind::TermoXsdOfficial,
        SerproExternalGateKind::CnpjAlphanumericSerialization,
        SerproExternalGateKind::ContractVigencyTariff,
        SerproExternalGateKind::SoftwareHouseLegalModel,
        SerproExternalGateKind::OpsRolesRpoRto,
    ];

    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Garante o conjunto mínimo de pendências da change de go-live.
     *
     * @return list<SerproExternalGate>
     */
    public function ensureBaselineGates(?SerproEnvironment $environment = null): array
    {
        $environment ??= SerproEnvironment::Production;

        $defs = [
            SerproExternalGateKind::OauthEndpointDivergence->value => [
                'title' => 'Chamado SERPRO: curl Área do Cliente vs /authenticate',
                'description' => 'Manter somente o endpoint canônico autenticacao.sapi.serpro.gov.br/authenticate até resposta formal. Fluxo alternativo na raiz do gateway permanece bloqueado.',
            ],
            SerproExternalGateKind::TermoXsdOfficial->value => [
                'title' => 'Solicitar XSD oficial do Termo de Autorização',
                'description' => 'Schema local é derivado e identificado como tal até XSD oficial versionado.',
            ],
            SerproExternalGateKind::CnpjAlphanumericSerialization->value => [
                'title' => 'Confirmar serialização de CNPJ alfanumérico no Termo/Eventos',
                'description' => 'FAQ declara suporte; páginas do Termo/Eventos ainda descrevem 14 dígitos numéricos.',
            ],
            SerproExternalGateKind::ContractVigencyTariff->value => [
                'title' => 'Revisão jurídico-comercial: vigência e tabela/ciclo tarifário',
                'description' => 'Divergência capa vs cláusulas; ciclo 21–20 e faixas oficiais exigem confirmação.',
            ],
            SerproExternalGateKind::SoftwareHouseLegalModel->value => [
                'title' => 'Revisão do modelo software-house / Termo em nome do escritório',
                'description' => 'Contrato permite arranjo, mas não substitui procuração RFB nem certificação jurídica da plataforma.',
            ],
            SerproExternalGateKind::OpsRolesRpoRto->value => [
                'title' => 'Definir responsáveis, on-call, RPO/RTO, escrow/KMS e custódia A1',
                'description' => 'Identidades de Office/cliente canário NÃO devem ser gravadas em artefatos OpenSpec versionados.',
            ],
        ];

        $gates = [];
        foreach ($defs as $kind => $meta) {
            $gates[] = SerproExternalGate::query()->firstOrCreate(
                ['kind' => $kind],
                [
                    'environment' => $environment->value,
                    'status' => SerproExternalGateStatus::Open->value,
                    'title' => $meta['title'],
                    'description' => $meta['description'],
                ]
            );
        }

        return $gates;
    }

    public function recordSubmission(
        SerproExternalGateKind $kind,
        string $ticketRef,
        ?string $evidenceRef = null,
        ?int $actorUserId = null,
    ): SerproExternalGate {
        $gate = SerproExternalGate::query()->where('kind', $kind->value)->first()
            ?? SerproExternalGate::query()->create([
                'kind' => $kind->value,
                'environment' => SerproEnvironment::Production->value,
                'status' => SerproExternalGateStatus::Open->value,
                'title' => $kind->label(),
            ]);

        $gate->forceFill([
            'status' => SerproExternalGateStatus::Submitted,
            'ticket_ref' => mb_substr($ticketRef, 0, 120),
            'evidence_ref' => $evidenceRef !== null ? mb_substr($evidenceRef, 0, 200) : null,
            'submitted_at' => now(),
            'updated_by_user_id' => $actorUserId,
        ])->save();

        $this->audit->record('serpro.external_gate.submitted', 'SUCCESS', $gate, [
            'kind' => $kind->value,
            'ticket_ref' => $gate->ticket_ref,
        ], $actorUserId, null);

        return $gate->refresh();
    }

    /**
     * Aceita gate Production com referência, resumo, responsável e data (sem waiver/PDF).
     */
    public function acceptGate(
        SerproExternalGateKind $kind,
        string $ticketRef,
        string $answerSummary,
        string $responsibleName,
        CarbonImmutable|string $referenceDate,
        ?int $actorUserId = null,
        ?SerproEnvironment $environment = null,
    ): SerproExternalGate {
        $environment ??= SerproEnvironment::Production;
        if ($environment !== SerproEnvironment::Production) {
            throw new RuntimeException('Gates externos bloqueadores aplicam-se a PRODUCTION.');
        }

        $ticketRef = trim($ticketRef);
        $answerSummary = trim($answerSummary);
        $responsibleName = trim($responsibleName);

        if ($ticketRef === '' || $answerSummary === '' || $responsibleName === '') {
            throw new RuntimeException('Referência, resumo e responsável são obrigatórios.');
        }

        $date = $referenceDate instanceof CarbonImmutable
            ? $referenceDate
            : CarbonImmutable::parse($referenceDate);

        $this->ensureBaselineGates($environment);

        $gate = SerproExternalGate::query()->where('kind', $kind->value)->firstOrFail();

        $gate->forceFill([
            'environment' => $environment->value,
            'status' => SerproExternalGateStatus::Accepted,
            'ticket_ref' => mb_substr($ticketRef, 0, 120),
            'answer_summary' => mb_substr($answerSummary, 0, 1000),
            'responsible_name' => mb_substr($responsibleName, 0, 200),
            'reference_date' => $date->toDateString(),
            'accepted_at' => now(),
            'answered_at' => $gate->answered_at ?? now(),
            'submitted_at' => $gate->submitted_at ?? now(),
            'updated_by_user_id' => $actorUserId,
        ])->save();

        if (! $gate->hasCompleteAcceptanceFields()) {
            throw new RuntimeException('Aceite incompleto: referência, resumo, responsável e data são obrigatórios.');
        }

        $this->audit->record('serpro.external_gate.accepted', 'SUCCESS', $gate, [
            'kind' => $kind->value,
            'ticket_ref' => $gate->ticket_ref,
            'responsible_name' => $gate->responsible_name,
            'reference_date' => $gate->reference_date?->toDateString(),
        ], $actorUserId, null);

        return $gate->refresh();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listSanitized(?SerproEnvironment $environment = null): array
    {
        $this->ensureBaselineGates($environment ?? SerproEnvironment::Production);

        $q = SerproExternalGate::query()->orderBy('kind');
        if ($environment !== null) {
            $q->where(function ($inner) use ($environment): void {
                $inner->where('environment', $environment->value)
                    ->orWhereNull('environment');
            });
        }

        return $q->get()->map->toSanitizedArray()->all();
    }

    public function anyBlockingProduction(?SerproEnvironment $environment = null): bool
    {
        $environment ??= SerproEnvironment::Production;
        $this->ensureBaselineGates($environment);

        return SerproExternalGate::query()
            ->get()
            ->contains(fn (SerproExternalGate $g) => $g->blocksProduction());
    }
}
