<?php

namespace App\Services\Integra\Parcelamento;

use App\Enums\FiscalFindingSeverity;
use App\Enums\FiscalSituation;
use App\Enums\TaxInstallmentModality;
use App\Enums\TaxInstallmentParcelStatus;
use App\Enums\TaxInstallmentPaymentStatus;
use App\Models\Client;
use App\Models\FiscalMonitoringRun;
use App\Models\Office;
use App\Models\TaxInstallmentOrder;
use App\Models\TaxInstallmentParcel;
use App\Models\TaxInstallmentPayment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Projeta pedido/modalidade/parcela/vencimento/pagamento.
 * Nunca declara inadimplência além do que a fonte informa.
 */
final class ParcelamentoProjectionService
{
    /**
     * @param  array<string, mixed>  $sourceBody  corpo normalizado da fonte
     * @return array{
     *     orders: list<TaxInstallmentOrder>,
     *     parcels: list<TaxInstallmentParcel>,
     *     payments: list<TaxInstallmentPayment>,
     *     findings: list<array<string, mixed>>,
     *     situation: FiscalSituation
     * }
     */
    public function projectFromMonitorBody(
        Office $office,
        Client $client,
        TaxInstallmentModality $modality,
        array $sourceBody,
        ?FiscalMonitoringRun $run = null,
        ?string $evidenceSha256 = null,
    ): array {
        return DB::transaction(function () use ($office, $client, $modality, $sourceBody, $run, $evidenceSha256) {
            $orders = [];
            $parcels = [];
            $payments = [];
            $findings = [];
            $worst = FiscalSituation::UpToDate;

            $pedidos = $sourceBody['pedidos'] ?? [];
            if ($pedidos === [] && isset($sourceBody['numeroParcelamento'])) {
                $pedidos = [[
                    'numero' => $sourceBody['numeroParcelamento'],
                    'situacao' => $sourceBody['situacao'] ?? 'UNKNOWN',
                    'quantidadeParcelas' => isset($sourceBody['parcelas']) ? count($sourceBody['parcelas']) : null,
                ]];
            }

            foreach ($pedidos as $pedido) {
                $externalId = (string) ($pedido['numero'] ?? $pedido['numeroParcelamento'] ?? '');
                if ($externalId === '') {
                    continue;
                }

                $sourceStatus = isset($pedido['situacao']) ? (string) $pedido['situacao'] : null;
                $orderSituation = $this->mapOrderSituation($sourceStatus);
                $order = TaxInstallmentOrder::query()->updateOrCreate(
                    [
                        'office_id' => $office->id,
                        'client_id' => $client->id,
                        'modality' => $modality->value,
                        'external_order_id' => $externalId,
                    ],
                    [
                        'run_id' => $run?->id,
                        'regime' => $modality->regime(),
                        'situation' => $orderSituation->value,
                        'source_status' => $sourceStatus,
                        'requested_at' => ! empty($pedido['dataPedido'])
                            ? CarbonImmutable::parse((string) $pedido['dataPedido'])
                            : null,
                        'consolidated_at' => ! empty($pedido['dataConsolidacao'])
                            ? CarbonImmutable::parse((string) $pedido['dataConsolidacao'])
                            : null,
                        'parcel_count' => isset($pedido['quantidadeParcelas'])
                            ? (int) $pedido['quantidadeParcelas']
                            : null,
                        'total_amount_cents' => isset($pedido['valorTotalCentavos'])
                            ? (int) $pedido['valorTotalCentavos']
                            : null,
                        'source_system' => ParcelamentoServiceCatalog::SOLUTION,
                        'source_service' => $modality->value,
                        'source_operation' => 'CONSULTAR_PEDIDOS',
                        'evidence_sha256' => $evidenceSha256,
                        'observed_at' => CarbonImmutable::now(),
                        'metadata' => [
                            'regime' => $modality->regime(),
                            'label' => $modality->label(),
                            'data_situacao' => $pedido['dataSituacao'] ?? null,
                            'alteracoes_divida' => $pedido['alteracoesDivida'] ?? [],
                            'detalhes_consolidacao' => $pedido['detalhesConsolidacao'] ?? [],
                        ],
                    ],
                );
                $orders[] = $order;
                $worst = $this->worseSituation($worst, $orderSituation);

                // O codec ancora cada parcela no pedido de origem. Nunca usar lista global.
                $parcelRows = is_array($pedido['parcelas'] ?? null) ? $pedido['parcelas'] : [];
                $paymentRows = is_array($pedido['pagamentos'] ?? null) ? $pedido['pagamentos'] : [];

                foreach ($parcelRows as $row) {
                    if (! is_array($row)) {
                        continue;
                    }
                    $projected = $this->projectParcel(
                        $office,
                        $client,
                        $order,
                        $modality,
                        $row,
                        is_array($paymentRows[$this->parcelKey($row)] ?? null)
                            ? $paymentRows[$this->parcelKey($row)]
                            : null,
                    );
                    $parcels[] = $projected['parcel'];
                    if ($projected['payment'] !== null) {
                        $payments[] = $projected['payment'];
                    }
                    foreach ($projected['findings'] as $f) {
                        $findings[] = $f;
                    }
                    $worst = $this->worseSituation($worst, $projected['situation']);
                }
            }

            if ($orders === []) {
                $worst = FiscalSituation::Unknown;
                $findings[] = [
                    'code' => 'PARCELAMENTO_SEM_PEDIDOS',
                    'severity' => FiscalFindingSeverity::Info->value,
                    'title' => 'Nenhum pedido na modalidade',
                    'detail' => "Modalidade {$modality->value} sem pedidos na resposta.",
                    'situation' => FiscalSituation::Unknown->value,
                    'creates_pending' => false,
                ];
            }

            $unassigned = $sourceBody['unassigned_available_parcels'] ?? [];
            if (is_array($unassigned) && $unassigned !== []) {
                $findings[] = [
                    'code' => 'PARCELAS_SEM_PEDIDO_CORRENTE',
                    'severity' => FiscalFindingSeverity::Info->value,
                    'title' => 'Parcelas disponíveis sem pedido corrente identificável',
                    'detail' => count($unassigned).' parcela(s) não foram persistidas porque a resposta não identifica o pedido.',
                    'situation' => FiscalSituation::Unknown->value,
                    'creates_pending' => false,
                ];
                $worst = $this->worseSituation($worst, FiscalSituation::Unknown);
            }

            return [
                'orders' => $orders,
                'parcels' => $parcels,
                'payments' => $payments,
                'findings' => $findings,
                'situation' => $worst,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>|null  $paymentRow
     * @return array{
     *     parcel: TaxInstallmentParcel,
     *     payment: ?TaxInstallmentPayment,
     *     findings: list<array<string, mixed>>,
     *     situation: FiscalSituation
     * }
     */
    public function projectParcel(
        Office $office,
        Client $client,
        TaxInstallmentOrder $order,
        TaxInstallmentModality $modality,
        array $row,
        ?array $paymentRow = null,
    ): array {
        $key = $this->parcelKey($row);
        $dueAt = ! empty($row['vencimento'])
            ? CarbonImmutable::parse((string) $row['vencimento'])->startOfDay()
            : null;
        $sourceStatus = isset($row['situacaoFonte'])
            ? (string) $row['situacaoFonte']
            : (isset($row['situacao']) ? (string) $row['situacao'] : null);

        $paymentConfirmed = $this->isPaymentConfirmed($paymentRow, $sourceStatus);
        $status = $this->resolveParcelStatus($dueAt, $paymentConfirmed, $sourceStatus, $row);
        $situation = match ($status) {
            TaxInstallmentParcelStatus::Paid => FiscalSituation::UpToDate,
            TaxInstallmentParcelStatus::Attention => FiscalSituation::Attention,
            TaxInstallmentParcelStatus::Pending => FiscalSituation::Pending,
            TaxInstallmentParcelStatus::AvailableToEmit => FiscalSituation::Pending,
            default => FiscalSituation::Unknown,
        };

        $logical = implode(':', [
            'PARC',
            $modality->value,
            $order->external_order_id,
            $key,
        ]);

        $parcel = TaxInstallmentParcel::query()->updateOrCreate(
            [
                'office_id' => $office->id,
                'order_id' => $order->id,
                'parcel_key' => $key,
            ],
            [
                'client_id' => $client->id,
                'modality' => $modality->value,
                'parcel_number' => isset($row['numero']) && is_numeric($row['numero'])
                    ? (int) $row['numero']
                    : null,
                'status' => $status,
                'source_status' => $sourceStatus,
                'due_at' => $dueAt,
                'amount_cents' => isset($row['valorCentavos']) ? (int) $row['valorCentavos'] : null,
                'document_available' => (bool) ($row['disponivel'] ?? false),
                'payment_status' => $paymentConfirmed
                    ? TaxInstallmentPaymentStatus::Confirmed
                    : TaxInstallmentPaymentStatus::None,
                'paid_at' => $paymentConfirmed && isset($paymentRow['dataPagamento'])
                    ? CarbonImmutable::parse((string) $paymentRow['dataPagamento'])
                    : null,
                'logical_key' => $logical,
                'metadata' => [
                    'source_status' => $sourceStatus,
                    'association_source' => $row['association_source'] ?? null,
                    // Explicitamente NÃO gravamos "inadimplente" inferido.
                    'overdue_without_payment_confirmation' => $status === TaxInstallmentParcelStatus::Attention,
                ],
            ],
        );

        $payment = null;
        if ($paymentConfirmed) {
            $ref = (string) ($paymentRow['referencia'] ?? ('CONF-'.$key));
            $payment = TaxInstallmentPayment::query()->updateOrCreate(
                [
                    'office_id' => $office->id,
                    'parcel_id' => $parcel->id,
                    'payment_ref' => $ref,
                ],
                [
                    'client_id' => $client->id,
                    'order_id' => $order->id,
                    'modality' => $modality->value,
                    'status' => TaxInstallmentPaymentStatus::Confirmed,
                    'amount_cents' => isset($paymentRow['valorPagoCentavos'])
                        ? (int) $paymentRow['valorPagoCentavos']
                        : ($parcel->amount_cents),
                    'paid_at' => isset($paymentRow['dataPagamento'])
                        ? CarbonImmutable::parse((string) $paymentRow['dataPagamento'])
                        : CarbonImmutable::now(),
                    'evidence_sha256' => isset($paymentRow['evidence_sha256'])
                        ? (string) $paymentRow['evidence_sha256']
                        : null,
                    'run_id' => $order->run_id,
                    'metadata' => ['from_source' => true],
                ],
            );
            $parcel->forceFill(['payment_id' => $payment->id])->save();
        }

        $findings = [];
        if ($status === TaxInstallmentParcelStatus::Attention) {
            $findings[] = [
                'code' => 'PARCELA_VENCIDA_SEM_PAGAMENTO',
                'severity' => FiscalFindingSeverity::Medium->value,
                'title' => 'Parcela vencida sem confirmação de pagamento',
                'detail' => "Modalidade {$modality->value}, pedido {$order->external_order_id}, parcela {$key}. "
                    .'Situação ATTENTION — sem afirmar inadimplência definitiva além da fonte.',
                'situation' => FiscalSituation::Attention->value,
                'creates_pending' => true,
                'due_at' => $dueAt?->toIso8601String(),
            ];
        }

        if ($paymentConfirmed) {
            $findings[] = [
                'code' => 'PARCELA_PAGAMENTO_CONFIRMADO',
                'severity' => FiscalFindingSeverity::Info->value,
                'title' => 'Pagamento confirmado pela fonte',
                'detail' => "Parcela {$key} com confirmação oficial vinculada ao pedido.",
                'situation' => FiscalSituation::UpToDate->value,
                'creates_pending' => false,
            ];
        }

        return [
            'parcel' => $parcel->fresh(),
            'payment' => $payment,
            'findings' => $findings,
            'situation' => $situation,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function parcelKey(array $row): string
    {
        if (isset($row['parcela'])) {
            return (string) $row['parcela'];
        }
        if (isset($row['anoMesParcela'])) {
            return (string) $row['anoMesParcela'];
        }
        if (isset($row['numero'])) {
            return (string) $row['numero'];
        }

        return 'UNKNOWN';
    }

    private function mapOrderSituation(?string $sourceStatus): FiscalSituation
    {
        if ($sourceStatus === null || $sourceStatus === '') {
            return FiscalSituation::Unknown;
        }

        $s = strtoupper($sourceStatus);

        return match (true) {
            str_contains($s, 'QUIT') || str_contains($s, 'ENCERR') => FiscalSituation::UpToDate,
            str_contains($s, 'ANDAMENTO') || str_contains($s, 'ATIVO') => FiscalSituation::Pending,
            str_contains($s, 'CANCEL') => FiscalSituation::NotApplicable,
            default => FiscalSituation::Unknown,
        };
    }

    /**
     * @param  array<string, mixed>|null  $paymentRow
     */
    private function isPaymentConfirmed(?array $paymentRow, ?string $sourceStatus): bool
    {
        if ($paymentRow !== null) {
            if (! empty($paymentRow['pagamentoConfirmado'])) {
                return true;
            }
            if (isset($paymentRow['status']) && strtoupper((string) $paymentRow['status']) === 'CONFIRMED') {
                return true;
            }
        }

        if ($sourceStatus !== null && in_array(strtoupper($sourceStatus), ['PAGA', 'PAGO', 'QUITADA'], true)) {
            return true;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function resolveParcelStatus(
        ?CarbonImmutable $dueAt,
        bool $paymentConfirmed,
        ?string $sourceStatus,
        array $row,
    ): TaxInstallmentParcelStatus {
        if ($paymentConfirmed) {
            return TaxInstallmentParcelStatus::Paid;
        }

        if ($sourceStatus !== null) {
            $s = strtoupper($sourceStatus);
            if (in_array($s, ['CANCELADA', 'CANCELADO'], true)) {
                return TaxInstallmentParcelStatus::Cancelled;
            }
        }

        if (! empty($row['disponivel'])) {
            // Disponível para emitir e vencida → ATTENTION (não inadimplente)
            if ($dueAt !== null && $dueAt->isPast()) {
                return TaxInstallmentParcelStatus::Attention;
            }

            return TaxInstallmentParcelStatus::AvailableToEmit;
        }

        if ($dueAt !== null && $dueAt->isPast()) {
            // Vencida sem confirmação oficial de pagamento: ATTENTION, nunca "INADIMPLENTE"
            return TaxInstallmentParcelStatus::Attention;
        }

        if ($sourceStatus !== null && str_contains(strtoupper($sourceStatus), 'ABERTO')) {
            return TaxInstallmentParcelStatus::Pending;
        }

        return TaxInstallmentParcelStatus::Open;
    }

    private function worseSituation(FiscalSituation $a, FiscalSituation $b): FiscalSituation
    {
        $rank = [
            FiscalSituation::UpToDate->value => 0,
            FiscalSituation::Unknown->value => 1,
            FiscalSituation::NotApplicable->value => 1,
            FiscalSituation::Pending->value => 2,
            FiscalSituation::Attention->value => 3,
            FiscalSituation::Processing->value => 2,
            FiscalSituation::Error->value => 4,
            FiscalSituation::Blocked->value => 4,
            FiscalSituation::Unsupported->value => 4,
        ];

        $ra = $rank[$a->value] ?? 1;
        $rb = $rank[$b->value] ?? 1;

        return $rb >= $ra ? $b : $a;
    }
}
