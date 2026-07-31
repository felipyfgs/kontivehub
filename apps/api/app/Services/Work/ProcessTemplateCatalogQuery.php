<?php

namespace App\Services\Work;

use App\Models\WorkProcessTemplate;
use App\Support\CurrentTenant;

final class ProcessTemplateCatalogQuery
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly ProcessTemplateCatalog $catalog,
    ) {}

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        $installed = WorkProcessTemplate::query()
            ->where('tenant_id', $this->currentTenant->id())
            ->whereNotNull('catalog_key')
            ->get()
            ->keyBy('catalog_key');

        return collect($this->catalog->all())
            ->map(function (array $definition) use ($installed): array {
                /** @var WorkProcessTemplate|null $template */
                $template = $installed->get($definition['key']);

                return [
                    ...$definition,
                    'installed' => $template !== null,
                    'installed_template_id' => $template?->id,
                    'installed_version' => $template?->catalog_version,
                    'update_available' => $template !== null
                        && (int) $template->catalog_version < (int) $definition['version'],
                ];
            })
            ->values()
            ->all();
    }
}
