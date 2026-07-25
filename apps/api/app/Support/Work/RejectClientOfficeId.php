<?php

namespace App\Support\Work;

use App\Support\CurrentOffice;
use Illuminate\Http\Request;

/**
 * Remove/rejeita office_id fornecido pelo cliente em payload ou query.
 * A autoridade do tenant é sempre {@see CurrentOffice}.
 */
final class RejectClientOfficeId
{
    /**
     * Remove office_id de request (query, body form e JSON).
     */
    public static function strip(Request $request): void
    {
        $request->request->remove('office_id');
        $request->query->remove('office_id');
        if ($request->isJson() && $request->json() !== null) {
            $request->json()->remove('office_id');
        }
    }

    /**
     * Remove office_id de arrays de filtros/payloads aninhados.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function stripFromArray(array $data): array
    {
        unset($data['office_id']);

        if (isset($data['filters']) && is_array($data['filters'])) {
            unset($data['filters']['office_id']);
        }

        return $data;
    }
}
