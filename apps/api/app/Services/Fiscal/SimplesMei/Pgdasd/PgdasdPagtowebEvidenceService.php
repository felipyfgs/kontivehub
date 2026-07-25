<?php

namespace App\Services\Fiscal\SimplesMei\Pgdasd;

use App\Models\Client;
use App\Models\Office;
use App\Models\PagtowebPaymentListItem;
use App\Models\PagtowebPaymentListObservation;
use App\Models\PgdasdOperation;
use App\Services\Fiscal\Guides\PagtowebPaymentListCodec;
use Carbon\CarbonImmutable;
use RuntimeException;

/** Aplica cobertura PAGTOWEB a DAS locais sem persistir o número na projeção de pagamentos. */
final class PgdasdPagtowebEvidenceService
{
    public function __construct(private readonly PagtowebPaymentListCodec $codec) {}

    /**
     * @param  list<string>  $consultedDigests
     * @return array{paid:int,not_found:int}
     */
    public function apply(
        Office $office,
        Client $client,
        PagtowebPaymentListObservation $observation,
        array $consultedDigests,
        ?int $sourceRunId,
        CarbonImmutable $verifiedAt,
    ): array {
        if ((int) $client->office_id !== (int) $office->id
            || (int) $observation->office_id !== (int) $office->id
            || (int) $observation->client_id !== (int) $client->id
        ) {
            throw new RuntimeException('Evidência PAGTOWEB fora do escritório atual.');
        }

        $consulted = array_fill_keys(array_values(array_unique(array_filter(
            $consultedDigests,
            static fn (mixed $digest): bool => is_string($digest)
                && preg_match('/^[a-f0-9]{64}$/', $digest) === 1,
        ))), true);
        if ($consulted === []) {
            return ['paid' => 0, 'not_found' => 0];
        }

        $items = PagtowebPaymentListItem::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->where('client_id', $client->id)
            ->where('observation_id', $observation->id)
            ->whereIn('document_digest', array_keys($consulted))
            ->get()
            ->keyBy('document_digest');

        $paid = 0;
        $notFound = 0;
        $operations = PgdasdOperation::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->where('client_id', $client->id)
            ->where('kind', 'DAS')
            ->whereNotNull('das_number')
            ->lockForUpdate()
            ->get();

        foreach ($operations as $operation) {
            $digest = $this->codec->documentDigest((string) $operation->das_number);
            if (! isset($consulted[$digest])) {
                continue;
            }

            /** @var PagtowebPaymentListItem|null $item */
            $item = $items->get($digest);
            if ($item !== null) {
                $operation->forceFill([
                    'pagtoweb_payment_status' => 'PAID',
                    'pagtoweb_verified_at' => $verifiedAt,
                    'pagtoweb_paid_at' => $item->paid_on,
                    'pagtoweb_amount_cents' => $this->amountCents($item->total_amount),
                    'pagtoweb_source_run_id' => $sourceRunId,
                    'pagtoweb_source_item_id' => $item->id,
                ])->save();
                $paid++;

                continue;
            }

            // Evidência positiva é permanente: uma consulta posterior não a rebaixa.
            if ($operation->pagtoweb_payment_status === 'PAID') {
                continue;
            }

            $operation->forceFill([
                'pagtoweb_payment_status' => 'NOT_FOUND',
                'pagtoweb_verified_at' => $verifiedAt,
                'pagtoweb_paid_at' => null,
                'pagtoweb_amount_cents' => null,
                'pagtoweb_source_run_id' => $sourceRunId,
                'pagtoweb_source_item_id' => null,
            ])->save();
            $notFound++;
        }

        return ['paid' => $paid, 'not_found' => $notFound];
    }

    private function amountCents(mixed $amount): ?int
    {
        if (! is_numeric($amount) || (float) $amount < 0) {
            return null;
        }

        return (int) round((float) $amount * 100);
    }
}
