<?php

namespace App\Services\Integra\Parcelamento;

use DateTimeImmutable;

/** Normaliza os quatro contratos de leitura oficiais do Integra-Parcelamento. */
final class ParcelamentoOfficialCodec
{
    /**
     * @param  array<string, mixed>  $ordersBody
     * @param  array<string, array<string, mixed>>  $detailsByOrder
     * @param  array<string, mixed>  $availableBody
     * @return array{
     *   pedidos:list<array<string,mixed>>,
     *   unassigned_available_parcels:list<array<string,mixed>>
     * }
     */
    public function normalizeMonitor(
        array $ordersBody,
        array $detailsByOrder,
        array $availableBody,
    ): array {
        $orders = $this->orders($ordersBody);
        $byId = [];

        foreach ($orders as $order) {
            $externalId = (string) $order['numero'];
            $detail = $this->orderDetail($detailsByOrder[$externalId] ?? [], $externalId);
            $detailFields = array_filter(
                $detail,
                static fn (mixed $value, string $key): bool => ! in_array($key, ['parcelas', 'pagamentos'], true)
                    && $value !== null,
                ARRAY_FILTER_USE_BOTH,
            );
            $byId[$externalId] = array_merge($order, $detailFields, [
                'parcelas' => $detail['parcelas'] ?? [],
                'pagamentos' => $detail['pagamentos'] ?? [],
            ]);
        }

        // Um detalhe pode chegar em consulta explícita sem ter vindo na listagem.
        foreach ($detailsByOrder as $externalId => $body) {
            $externalId = trim((string) $externalId);
            if ($externalId === '' || isset($byId[$externalId])) {
                continue;
            }
            $detail = $this->orderDetail($body, $externalId);
            $byId[$externalId] = $detail;
        }

        $available = $this->availableParcels($availableBody);
        $targetId = $this->currentOrderId(array_values($byId));
        if ($targetId !== null) {
            $current = $byId[$targetId];
            $current['parcelas'] = $this->mergeParcels(
                is_array($current['parcelas'] ?? null) ? $current['parcelas'] : [],
                $available,
            );
            $byId[$targetId] = $current;
            $available = [];
        }

        return [
            'pedidos' => array_values($byId),
            'unassigned_available_parcels' => $available,
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return list<array<string, mixed>>
     */
    public function orders(array $body): array
    {
        $rows = $body['parcelamentos'] ?? $body['pedidos'] ?? [];
        if (! is_array($rows)) {
            return [];
        }

        $orders = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $externalId = trim((string) ($row['numero'] ?? $row['numeroParcelamento'] ?? ''));
            if ($externalId === '') {
                continue;
            }
            $orders[] = [
                'numero' => $externalId,
                'situacao' => $this->nullableString($row['situacao'] ?? null),
                'dataPedido' => $this->date($row['dataDoPedido'] ?? $row['dataPedido'] ?? null),
                'dataSituacao' => $this->date($row['dataDaSituacao'] ?? $row['dataSituacao'] ?? null),
                'parcelas' => [],
                'pagamentos' => [],
            ];
        }

        return $orders;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function orderDetail(array $body, string $fallbackExternalId = ''): array
    {
        $externalId = trim((string) ($body['numero'] ?? $body['numeroParcelamento'] ?? $fallbackExternalId));
        $consolidation = is_array($body['consolidacaoOriginal'] ?? null)
            ? $body['consolidacaoOriginal']
            : [];
        $paymentRows = $body['demonstrativoPagamentos'] ?? $consolidation['demonstrativoPagamentos'] ?? [];
        $payments = [];
        $parcels = [];

        if (is_array($paymentRows)) {
            foreach ($paymentRows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $key = trim((string) ($row['mesDaParcela'] ?? $row['anoMesParcela'] ?? ''));
                if ($key === '') {
                    continue;
                }
                $payment = [
                    'referencia' => $this->nullableString($row['numeroDas'] ?? null) ?? "PAGO-{$externalId}-{$key}",
                    'numeroParcelamento' => $externalId,
                    'anoMesParcela' => $key,
                    'pagamentoConfirmado' => true,
                    'dataPagamento' => $this->date($row['dataDeArrecadacao'] ?? $row['dataPagamento'] ?? null),
                    'valorPagoCentavos' => $this->moneyToCents($row['valorPago'] ?? $row['valorPagoArrecadacao'] ?? null),
                ];
                $payments[$key] = $payment;
                $parcels[] = [
                    'parcela' => $key,
                    'vencimento' => $this->date($row['vencimentoDoDas'] ?? $row['dataVencimento'] ?? null),
                    'valorCentavos' => $payment['valorPagoCentavos'],
                    'disponivel' => false,
                    'situacaoFonte' => 'PAGA',
                ];
            }
        }

        return [
            'numero' => $externalId,
            'situacao' => $this->nullableString($body['situacao'] ?? null),
            'dataPedido' => $this->date($body['dataDoPedido'] ?? $body['dataPedido'] ?? null),
            'dataSituacao' => $this->date($body['dataDaSituacao'] ?? $body['dataSituacao'] ?? null),
            'dataConsolidacao' => $this->dateTime($consolidation['dataConsolidacao'] ?? $body['dataConsolidacao'] ?? null),
            'valorTotalCentavos' => $this->moneyToCents(
                $consolidation['valorTotalConsolidado'] ?? $body['valorTotalConsolidado'] ?? null,
            ),
            'quantidadeParcelas' => $this->nullableInt(
                $consolidation['quantidadeParcelas'] ?? $body['quantidadeParcelas'] ?? null,
            ),
            'parcelas' => $parcels,
            'pagamentos' => $payments,
            'alteracoesDivida' => is_array($body['alteracoesDivida'] ?? null) ? $body['alteracoesDivida'] : [],
            'detalhesConsolidacao' => is_array($consolidation['detalhesConsolidacao'] ?? null)
                ? $consolidation['detalhesConsolidacao']
                : [],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return list<array<string, mixed>>
     */
    public function availableParcels(array $body): array
    {
        $rows = $body['listaParcelas'] ?? $body['listaParcela'] ?? $body['parcelasParaGerar'] ?? [];
        if (! is_array($rows)) {
            return [];
        }

        $parcels = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $key = trim((string) ($row['parcela'] ?? $row['anoMesParcela'] ?? ''));
            if ($key === '') {
                continue;
            }
            $parcels[] = [
                'parcela' => $key,
                'vencimento' => $this->date($row['vencimento'] ?? $row['dataVencimento'] ?? null),
                'valorCentavos' => $this->moneyToCents($row['valor'] ?? null),
                'disponivel' => true,
                'situacaoFonte' => 'DISPONIVEL_PARA_EMISSAO',
                'association_source' => 'LATEST_CURRENT_ORDER',
            ];
        }

        return $parcels;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function paymentDetail(array $body, string $orderId, string $parcelKey): array
    {
        return [
            'referencia' => $this->nullableString($body['numeroDas'] ?? null) ?? "PAGTO-{$orderId}-{$parcelKey}",
            'numeroParcelamento' => (string) ($body['numeroParcelamento'] ?? $orderId),
            'anoMesParcela' => (string) ($body['paDasGerado'] ?? $body['numeroParcela'] ?? $parcelKey),
            'pagamentoConfirmado' => $this->date($body['dataPagamento'] ?? null) !== null,
            'dataPagamento' => $this->date($body['dataPagamento'] ?? null),
            'valorPagoCentavos' => $this->moneyToCents($body['valorPagoArrecadacao'] ?? null),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $orders
     */
    private function currentOrderId(array $orders): ?string
    {
        $eligible = array_values(array_filter($orders, function (array $order): bool {
            $status = $this->upper((string) ($order['situacao'] ?? ''));

            return ! str_contains($status, 'ENCERR')
                && ! str_contains($status, 'CANCEL')
                && ! str_contains($status, 'QUIT');
        }));
        if ($eligible === []) {
            return null;
        }

        usort($eligible, static function (array $left, array $right): int {
            $leftSort = (string) ($left['dataPedido'] ?? '').'|'.str_pad((string) ($left['numero'] ?? ''), 32, '0', STR_PAD_LEFT);
            $rightSort = (string) ($right['dataPedido'] ?? '').'|'.str_pad((string) ($right['numero'] ?? ''), 32, '0', STR_PAD_LEFT);

            return $rightSort <=> $leftSort;
        });

        $id = trim((string) ($eligible[0]['numero'] ?? ''));

        return $id !== '' ? $id : null;
    }

    /**
     * @param  list<array<string, mixed>>  $base
     * @param  list<array<string, mixed>>  $incoming
     * @return list<array<string, mixed>>
     */
    private function mergeParcels(array $base, array $incoming): array
    {
        $byKey = [];
        foreach ([...$base, ...$incoming] as $parcel) {
            $key = trim((string) ($parcel['parcela'] ?? $parcel['anoMesParcela'] ?? ''));
            if ($key === '') {
                continue;
            }
            $byKey[$key] = array_merge($byKey[$key] ?? [], $parcel);
        }

        ksort($byKey);

        return array_values($byKey);
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function date(mixed $value): ?string
    {
        $digits = preg_replace('/\D/', '', (string) ($value ?? '')) ?? '';
        if (strlen($digits) === 8) {
            $date = DateTimeImmutable::createFromFormat('!Ymd', $digits);

            return $date !== false && $date->format('Ymd') === $digits ? $date->format('Y-m-d') : null;
        }

        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}/', $value) === 1) {
            return substr($value, 0, 10);
        }

        return null;
    }

    private function dateTime(mixed $value): ?string
    {
        $digits = preg_replace('/\D/', '', (string) ($value ?? '')) ?? '';
        if (strlen($digits) === 14) {
            $date = DateTimeImmutable::createFromFormat('!YmdHis', $digits);

            return $date !== false && $date->format('YmdHis') === $digits
                ? $date->format('Y-m-d H:i:s')
                : null;
        }

        return $this->date($value);
    }

    private function moneyToCents(mixed $value): ?int
    {
        if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
            return null;
        }
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        $negative = str_starts_with($raw, '-');
        $raw = preg_replace('/[^0-9,.]/', '', ltrim($raw, '+-')) ?? '';
        if ($raw === '') {
            return null;
        }
        if (str_contains($raw, ',')) {
            $raw = str_replace('.', '', $raw);
            $raw = str_replace(',', '.', $raw);
        }
        if (substr_count($raw, '.') > 1 || preg_match('/^\d+(?:\.\d+)?$/', $raw) !== 1) {
            return null;
        }

        [$whole, $fraction] = array_pad(explode('.', $raw, 2), 2, '');
        $fraction = str_pad($fraction, 3, '0');
        $cents = ((int) $whole * 100) + (int) substr($fraction, 0, 2);
        if ((int) ($fraction[2] ?? '0') >= 5) {
            $cents++;
        }

        return $negative ? -$cents : $cents;
    }

    private function upper(string $value): string
    {
        return function_exists('mb_strtoupper') ? mb_strtoupper($value, 'UTF-8') : strtoupper($value);
    }
}
