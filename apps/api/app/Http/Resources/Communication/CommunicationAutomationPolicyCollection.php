<?php

namespace App\Http\Resources\Communication;

use App\DTO\Communication\CommunicationAutomationIndexData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

final class CommunicationAutomationPolicyCollection extends ResourceCollection
{
    /** @var class-string */
    public $collects = CommunicationAutomationPolicyResource::class;

    public function __construct(
        private readonly CommunicationAutomationIndexData $index,
    ) {
        parent::__construct($index->policies);
    }

    /** @return array<string, mixed> */
    public function with(Request $request): array
    {
        return [
            'meta' => [
                'supported_scopes' => $this->index->supportedScopes,
                'inboxes' => CommunicationAutomationInboxResource::collection($this->index->inboxes),
                'tenant_enabled' => $this->index->tenantEnabled,
                'global_enabled' => $this->index->globalEnabled,
            ],
        ];
    }
}
