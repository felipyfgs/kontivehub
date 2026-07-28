<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

/** @mixin array<string, mixed> */
final class TenantMemberResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $member = Arr::only($this->resource, [
            'id',
            'user_id',
            'name',
            'email',
            'role',
            'permission_profile',
            'is_active',
            'status',
            'activation',
        ]);

        if (isset($member['permission_profile'])
            && is_array($member['permission_profile'])) {
            $member['permission_profile'] = Arr::only(
                $member['permission_profile'],
                ['id', 'key', 'name'],
            );
        }

        if (isset($member['activation'])
            && is_array($member['activation'])) {
            $member['activation'] = $this->activation(
                $member['activation'],
            );
        }

        return $member;
    }

    /** @param array<string, mixed> $activation */
    private function activation(array $activation): array
    {
        return Arr::only($activation, [
            'id',
            'purpose',
            'method',
            'status',
            'expires_at',
            'consumed_at',
            'revoked_at',
            'generation',
            'email_masked',
        ]);
    }
}
