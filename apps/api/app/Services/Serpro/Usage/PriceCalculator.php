<?php

namespace App\Services\Serpro\Usage;

use App\Enums\SerproConsumptionClass;
use App\Models\SerproPriceTier;
use App\Models\SerproPriceVersion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Cálculo de custo estimado por faixas configuráveis (sem hardcode no client HTTP).
 * Preserva versão de preço usada no momento da reserva/finalização.
 * Tabelas shadow NÃO autorizam produção quando productionOnly=true.
 */
final class PriceCalculator
{
    public function __construct(
        private readonly UsageShadowPolicy $shadow,
    ) {}

    /**
     * Resolve a versão de preço vigente no instante informado.
     */
    public function resolveVersion(Carbon|string|null $at = null, ?bool $productionOnly = null): ?SerproPriceVersion
    {
        $at = $at instanceof Carbon ? $at : ($at ? Carbon::parse($at) : now());
        $productionOnly ??= $this->shadow->requiresProductionPriceTable();

        $query = SerproPriceVersion::query()
            ->where('is_active', true)
            ->where('effective_from', '<=', $at)
            ->where(function ($q) use ($at): void {
                $q->whereNull('effective_to')->orWhere('effective_to', '>=', $at);
            });

        if ($productionOnly) {
            $query->where('authorizes_production', true)
                ->where('eligibility', 'PRODUCTION');
        }

        return $query->orderByDesc('effective_from')->first();
    }

    /**
     * @return array{
     *     price_version_id: int|null,
     *     estimated_cost_micros: int|null,
     *     unit_cost_micros: int|null,
     *     currency: string|null,
     *     price_unknown: bool,
     *     authorizes_production: bool,
     *     price_revision: string|null
     * }
     */
    public function estimate(
        SerproConsumptionClass $class,
        int $quantity = 1,
        ?string $systemCode = null,
        ?string $serviceCode = null,
        ?string $operationCode = null,
        Carbon|string|null $at = null,
        ?SerproPriceVersion $version = null,
        ?bool $productionOnly = null,
    ): array {
        $productionOnly ??= $this->shadow->requiresProductionPriceTable();

        if (! $class->allowsCostEstimate()) {
            $v = $version ?? $this->resolveVersion($at, $productionOnly);

            return [
                'price_version_id' => $v?->id,
                'estimated_cost_micros' => null,
                'unit_cost_micros' => null,
                'currency' => $v?->currency,
                'price_unknown' => $class === SerproConsumptionClass::Desconhecida,
                'authorizes_production' => $v?->authorizesProductiveEgress() ?? false,
                'price_revision' => $v?->source_revision ?? $v?->version_code,
            ];
        }

        $version ??= $this->resolveVersion($at, $productionOnly);

        if ($version === null) {
            return [
                'price_version_id' => null,
                'estimated_cost_micros' => null,
                'unit_cost_micros' => null,
                'currency' => null,
                'price_unknown' => true,
                'authorizes_production' => false,
                'price_revision' => null,
            ];
        }

        if ($class === SerproConsumptionClass::NaoFaturavel) {
            $tier = $this->findTier($version, $class, $quantity, $systemCode, $serviceCode, $operationCode);

            return [
                'price_version_id' => $version->id,
                'estimated_cost_micros' => 0,
                'unit_cost_micros' => $tier?->unit_cost_micros ?? 0,
                'currency' => $version->currency,
                'price_unknown' => false,
                'authorizes_production' => $version->authorizesProductiveEgress(),
                'price_revision' => $version->source_revision ?? $version->version_code,
            ];
        }

        $tier = $this->findTier($version, $class, $quantity, $systemCode, $serviceCode, $operationCode);

        if ($tier === null) {
            return [
                'price_version_id' => $version->id,
                'estimated_cost_micros' => null,
                'unit_cost_micros' => null,
                'currency' => $version->currency,
                'price_unknown' => true,
                'authorizes_production' => $version->authorizesProductiveEgress(),
                'price_revision' => $version->source_revision ?? $version->version_code,
            ];
        }

        return [
            'price_version_id' => $version->id,
            'estimated_cost_micros' => $tier->unit_cost_micros * max(1, $quantity),
            'unit_cost_micros' => $tier->unit_cost_micros,
            'currency' => $version->currency,
            'price_unknown' => false,
            'authorizes_production' => $version->authorizesProductiveEgress(),
            'price_revision' => $version->source_revision ?? $version->version_code,
        ];
    }

    private function findTier(
        SerproPriceVersion $version,
        SerproConsumptionClass $class,
        int $quantity,
        ?string $systemCode,
        ?string $serviceCode,
        ?string $operationCode,
    ): ?SerproPriceTier {
        /** @var Collection<int, SerproPriceTier> $tiers */
        $tiers = $version->relationLoaded('tiers')
            ? $version->tiers
            : $version->tiers()->get();

        $candidates = $tiers
            ->filter(fn (SerproPriceTier $t) => $t->consumption_class === $class)
            ->filter(fn (SerproPriceTier $t) => $t->matchesQuantity($quantity))
            ->sortByDesc(fn (SerproPriceTier $t) => $this->specificityScore($t, $systemCode, $serviceCode, $operationCode))
            ->values();

        foreach ($candidates as $tier) {
            if ($this->tierMatches($tier, $systemCode, $serviceCode, $operationCode)) {
                return $tier;
            }
        }

        return null;
    }

    private function tierMatches(
        SerproPriceTier $tier,
        ?string $systemCode,
        ?string $serviceCode,
        ?string $operationCode,
    ): bool {
        if ($tier->operation_code !== null && $tier->operation_code !== $operationCode) {
            return false;
        }
        if ($tier->service_code !== null && $tier->service_code !== $serviceCode) {
            return false;
        }
        if ($tier->system_code !== null && $tier->system_code !== $systemCode) {
            return false;
        }

        return true;
    }

    private function specificityScore(
        SerproPriceTier $tier,
        ?string $systemCode,
        ?string $serviceCode,
        ?string $operationCode,
    ): int {
        $score = 0;
        if ($tier->system_code !== null && $tier->system_code === $systemCode) {
            $score += 4;
        } elseif ($tier->system_code !== null) {
            return -1;
        }
        if ($tier->service_code !== null && $tier->service_code === $serviceCode) {
            $score += 2;
        } elseif ($tier->service_code !== null) {
            return -1;
        }
        if ($tier->operation_code !== null && $tier->operation_code === $operationCode) {
            $score += 1;
        } elseif ($tier->operation_code !== null) {
            return -1;
        }

        return $score;
    }
}
