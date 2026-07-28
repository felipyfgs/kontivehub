<?php

namespace App\Http\Resources;

use App\DTO\Tenant\TenantMemberListData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TenantMemberListData */
final class TenantMemberListResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return TenantMemberResource::collection($this->members)
            ->resolve($request);
    }

    /** @return array<string, mixed> */
    public function with(Request $request): array
    {
        return [
            'meta' => [
                'occupied_seats' => $this->occupiedSeats,
                'max_users' => $this->maxUsers,
            ],
        ];
    }
}
