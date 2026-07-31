<?php

namespace App\Http\Resources\Communication;

use App\DTO\Communication\InboxIndexData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

final class InboxCollection extends ResourceCollection
{
    /** @var class-string */
    public $collects = InboxResource::class;

    public function __construct(
        private readonly InboxIndexData $index,
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
                'departments' => DepartmentSummaryResource::collection(
                    $this->index->departments,
                ),
            ],
        ];
    }
}
