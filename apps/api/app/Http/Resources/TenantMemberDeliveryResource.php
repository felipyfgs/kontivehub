<?php

namespace App\Http\Resources;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

/** @mixin array<string, mixed> */
final class TenantMemberDeliveryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $payload = Arr::only($this->resource, [
            'credential_delivery',
            'method',
            'expires_at',
            'activation_url',
            'temporary_password',
            'immediate',
            'activation',
            'membership',
            'member',
        ]);

        foreach (['membership', 'member'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                $payload[$key] = TenantMemberResource::make(
                    $payload[$key],
                )->resolve($request);
            }
        }

        if (isset($payload['activation']) && is_array($payload['activation'])) {
            $payload['activation'] = Arr::only($payload['activation'], [
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

        return $payload;
    }

    public function withResponse(
        Request $request,
        JsonResponse $response,
    ): void {
        $response->headers->set('Cache-Control', 'no-store');
    }
}
