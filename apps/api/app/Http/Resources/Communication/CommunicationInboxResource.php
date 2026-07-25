<?php

namespace App\Http\Resources\Communication;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CommunicationInboxResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status?->value ?? $this->status,
            'address_masked' => $this->address_masked,
            'is_enabled' => (bool) $this->is_enabled,
            'is_default' => (bool) $this->is_default,
            'work_department_id' => $this->work_department_id,
            'lock_version' => (int) $this->lock_version,
            'connected_at' => $this->connected_at?->toIso8601String(),
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'members_count' => $this->whenCounted('members'),
            'member_ids' => $this->whenLoaded('members', fn () => $this->members
                ->pluck('office_membership_id')->map(fn ($id) => (int) $id)->values()),
            'members' => $this->whenLoaded('members', fn () => $this->members->map(fn ($member) => [
                'id' => (int) $member->office_membership_id,
                'name' => $member->relationLoaded('membership')
                    && $member->membership?->relationLoaded('user')
                    ? $member->membership?->user?->name
                    : null,
            ])->values()),
        ];
    }
}
