<?php

namespace App\Services\Serpro\Usage;

use App\Models\SerproPriceTier;
use App\Models\SerproPriceVersion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

/**
 * Importa faixas contratuais aprovadas (micros BRL) com fonte/hash/vigência.
 * Tabelas shadow NÃO recebem authorizes_production.
 */
final class ContractPriceTableImporter
{
    public function defaultManifestPath(): string
    {
        return resource_path('serpro/pricing/contract-tiers.v2026-07-16.json');
    }

    /**
     * @return array{
     *   price_version_id: int,
     *   version_code: string,
     *   tiers_imported: int,
     *   shadow_demoted: int,
     *   source_hash: string|null
     * }
     */
    public function importFromFile(?string $path = null, bool $demoteShadow = true): array
    {
        $path ??= $this->defaultManifestPath();
        if (! File::isFile($path)) {
            throw new InvalidArgumentException("Manifesto de preços não encontrado: {$path}");
        }

        $raw = File::get($path);
        $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($payload)) {
            throw new InvalidArgumentException('Manifesto de preços inválido.');
        }

        $fileHash = hash('sha256', $raw);
        $declared = isset($payload['source_hash']) ? (string) $payload['source_hash'] : null;

        return $this->import($payload, $demoteShadow, $declared ?? $fileHash, $fileHash);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *   price_version_id: int,
     *   version_code: string,
     *   tiers_imported: int,
     *   shadow_demoted: int,
     *   source_hash: string|null
     * }
     */
    public function import(
        array $payload,
        bool $demoteShadow = true,
        ?string $sourceHash = null,
        ?string $contentHash = null,
    ): array {
        $versionCode = (string) ($payload['version_code'] ?? '');
        if ($versionCode === '') {
            throw new InvalidArgumentException('version_code obrigatório no manifesto de preços.');
        }

        $tiers = $payload['tiers'] ?? null;
        if (! is_array($tiers) || $tiers === []) {
            throw new InvalidArgumentException('tiers obrigatório e não-vazio.');
        }

        $eligibility = strtoupper((string) ($payload['eligibility'] ?? 'PRODUCTION'));
        $authorizes = (bool) ($payload['authorizes_production'] ?? ($eligibility === 'PRODUCTION'));
        if ($eligibility === 'SHADOW') {
            $authorizes = false;
        }

        $from = Carbon::parse((string) ($payload['effective_from'] ?? now()->toIso8601String()));
        $to = isset($payload['effective_to']) && $payload['effective_to'] !== null
            ? Carbon::parse((string) $payload['effective_to'])
            : null;

        return DB::transaction(function () use (
            $payload,
            $versionCode,
            $tiers,
            $eligibility,
            $authorizes,
            $from,
            $to,
            $sourceHash,
            $contentHash,
            $demoteShadow,
        ): array {
            $shadowDemoted = 0;
            if ($demoteShadow && $authorizes) {
                $shadowDemoted = SerproPriceVersion::query()
                    ->where('version_code', '!=', $versionCode)
                    ->where(function ($q): void {
                        $q->where('eligibility', 'SHADOW')
                            ->orWhere('version_code', 'like', '%shadow%')
                            ->orWhere('authorizes_production', true);
                    })
                    ->update([
                        'authorizes_production' => false,
                        'eligibility' => 'SHADOW',
                    ]);
            }

            /** @var SerproPriceVersion $version */
            $version = SerproPriceVersion::query()->updateOrCreate(
                ['version_code' => $versionCode],
                [
                    'name' => (string) ($payload['name'] ?? $versionCode),
                    'effective_from' => $from,
                    'effective_to' => $to,
                    'is_active' => true,
                    'currency' => (string) ($payload['currency'] ?? 'BRL'),
                    'notes' => isset($payload['notes']) ? (string) $payload['notes'] : null,
                    'source_url' => isset($payload['source_url']) ? (string) $payload['source_url'] : null,
                    'source_hash' => $sourceHash ?? ($payload['source_hash'] ?? $contentHash),
                    'source_revision' => isset($payload['source_revision'])
                        ? (string) $payload['source_revision']
                        : null,
                    'eligibility' => $eligibility,
                    'authorizes_production' => $authorizes,
                    'billing_cycle_kind' => (string) ($payload['billing_cycle'] ?? 'D21_D20'),
                ],
            );

            SerproPriceTier::query()->where('price_version_id', $version->id)->delete();

            $count = 0;
            foreach ($tiers as $tier) {
                if (! is_array($tier)) {
                    continue;
                }
                SerproPriceTier::query()->create([
                    'price_version_id' => $version->id,
                    'consumption_class' => (string) $tier['consumption_class'],
                    'system_code' => $tier['system_code'] ?? null,
                    'service_code' => $tier['service_code'] ?? null,
                    'operation_code' => $tier['operation_code'] ?? null,
                    'min_quantity' => (int) ($tier['min_quantity'] ?? 1),
                    'max_quantity' => array_key_exists('max_quantity', $tier) && $tier['max_quantity'] !== null
                        ? (int) $tier['max_quantity']
                        : null,
                    'unit_cost_micros' => (int) $tier['unit_cost_micros'],
                    'sort_order' => (int) ($tier['sort_order'] ?? 0),
                ]);
                $count++;
            }

            return [
                'price_version_id' => (int) $version->id,
                'version_code' => $versionCode,
                'tiers_imported' => $count,
                'shadow_demoted' => (int) $shadowDemoted,
                'source_hash' => $version->source_hash,
            ];
        });
    }
}
