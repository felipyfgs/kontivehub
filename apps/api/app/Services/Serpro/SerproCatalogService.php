<?php

namespace App\Services\Serpro;

use App\Enums\SerproEnvironment;
use App\Models\SerproServiceCatalogEntry;

final class SerproCatalogService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function listForEnvironment(SerproEnvironment $environment, ?int $version = null): array
    {
        $q = SerproServiceCatalogEntry::query()
            ->where('environment', $environment->value)
            ->orderBy('solution_code')
            ->orderBy('service_code')
            ->orderBy('operation_code');

        if ($version !== null) {
            $q->where('catalog_version', $version);
        } else {
            $max = (int) SerproServiceCatalogEntry::query()
                ->where('environment', $environment->value)
                ->max('catalog_version');
            if ($max > 0) {
                $q->where('catalog_version', $max);
            }
        }

        return $q->get()->map(fn (SerproServiceCatalogEntry $e) => $e->toPublicArray())->all();
    }

    public function find(
        SerproEnvironment $environment,
        string $solution,
        string $service,
        string $operation,
    ): ?SerproServiceCatalogEntry {
        return SerproServiceCatalogEntry::query()
            ->where('environment', $environment->value)
            ->where('solution_code', $solution)
            ->where('service_code', $service)
            ->where('operation_code', $operation)
            ->where('is_enabled', true)
            ->orderByDesc('catalog_version')
            ->first();
    }
}
