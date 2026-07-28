<?php

namespace App\Http\Resources\Communication;

use App\DTO\Communication\CommunicationInboxIndexData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

final class CommunicationInboxCollection extends ResourceCollection
{
    /** @var class-string */
    public $collects = CommunicationInboxResource::class;

    public function __construct(
        private readonly CommunicationInboxIndexData $index,
    ) {
        parent::__construct($index->inboxes);
    }

    /** @return array<string, mixed> */
    public function with(Request $request): array
    {
        return [
            'meta' => [
                'global_enabled' => $this->index->globalEnabled,
                'gateway_enabled' => $this->index->gatewayEnabled,
                'tenant_enabled' => $this->index->tenantEnabled,
                'departments' => CommunicationDepartmentSummaryResource::collection(
                    $this->index->departments,
                ),
            ],
        ];
    }
}
