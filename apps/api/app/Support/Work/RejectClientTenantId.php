<?php

namespace App\Support\Work;

use App\Support\CurrentTenant;
use Illuminate\Http\Request;

/**
 * Remove/rejeita tenant_id fornecido pelo cliente em payload ou query.
 * A autoridade do tenant é sempre {@see CurrentTenant}.
 */
final class RejectClientTenantId
{
    /**
     * Remove tenant_id de request (query, body form e JSON).
     */
    public static function strip(Request $request): void
    {
        $request->request->remove('tenant_id');
        $request->query->remove('tenant_id');
        if ($request->isJson() && $request->json() !== null) {
            $request->json()->remove('tenant_id');
        }
    }

    /**
     * Remove tenant_id de arrays de filtros/payloads aninhados.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function stripFromArray(array $data): array
    {
        unset($data['tenant_id']);

        if (isset($data['filters']) && is_array($data['filters'])) {
            unset($data['filters']['tenant_id']);
        }

        return $data;
    }
}
