<?php

namespace App\Http\Resources\Communication;

use App\DTO\Communication\InboxCommandResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class InboxCommandResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var InboxCommandResult $result */
        $result = $this->resource;

        return [
            'command_id' => $result->commandId,
            'type' => $result->type->value,
            'status' => $result->status->value,
            'deleted' => $this->when($result->deleted !== null, $result->deleted),
        ];
    }
}
