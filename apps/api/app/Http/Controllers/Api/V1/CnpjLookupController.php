<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\CnpjRegistrationLookup;
use App\Domain\Cnpj;
use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;
use RuntimeException;

class CnpjLookupController extends Controller
{
    public function __invoke(string $cnpj, CnpjRegistrationLookup $lookup): JsonResponse
    {
        $this->authorize('create', Client::class);

        try {
            $normalized = Cnpj::parse($cnpj)->value();
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        if (! ctype_digit($normalized)) {
            return response()->json([
                'message' => 'A consulta pública ainda aceita somente CNPJ numérico. Preencha o cadastro manualmente.',
            ], 422);
        }

        try {
            $result = $lookup->find($normalized);

            return response()->json(['data' => $result->toArray()]);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 503);
        }
    }
}
