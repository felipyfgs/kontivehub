<?php

namespace App\Http\Resources\Communication;

use App\DTO\Communication\AutomationIndexData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

final class AutomationPolicyCollection extends ResourceCollection
{
    /** @var class-string */
    public $collects = AutomationPolicyResource::class;

    public function __construct(
        private readonly AutomationIndexData $index,
    ) {
        parent::__construct($index->policies);
    }

    /** @return array<string, mixed> */
    public function with(Request $request): array
    {
        return [
            'meta' => [
                'supported_scopes' => $this->index->supportedScopes,
                'inboxes' => AutomationInboxResource::collection($this->index->inboxes),
                'tenant_enabled' => $this->index->tenantEnabled,
                'global_enabled' => $this->index->globalEnabled,
            ],
        ];
    }
}
