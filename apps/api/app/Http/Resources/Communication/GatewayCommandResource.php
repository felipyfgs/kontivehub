<?php

namespace App\Http\Resources\Communication;

use App\DTO\Communication\GatewayCommandResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class GatewayCommandResource extends JsonResource
{
    /** @return array<string, string> */
    public function toArray(Request $request): array
    {
        /** @var GatewayCommandResult $result */
        $result = $this->resource;

        return [
            'command_id' => $result->commandId,
            'type' => $result->type->value,
            'status' => $result->status->value,
        ];
    }
}
