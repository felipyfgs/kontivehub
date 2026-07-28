<?php

namespace App\Http\Controllers\Api\V1\Fiscal;

use App\Actions\Fiscal\Mutations\GenerateMeiDasAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Fiscal\Mei\GenerateMeiDasPreflightRequest;
use App\Http\Requests\Fiscal\Mei\GenerateMeiDasRequest;
use Illuminate\Http\JsonResponse;

final class MeiDasController extends Controller
{
    public function __construct(
        private readonly GenerateMeiDasAction $generate,
    ) {}

    public function preflight(GenerateMeiDasPreflightRequest $request): JsonResponse
    {
        $result = $this->generate->preflight(
            $request->actor(),
            $request->generateData(),
        );

        return response()->json(['data' => $result->toArray()], $result->eligible ? 200 : 422);
    }

    public function store(GenerateMeiDasRequest $request): JsonResponse
    {
        $result = $this->generate->execute(
            $request->actor(),
            $request->generateData(),
        );

        return response()->json([
            'data' => [
                'mutation' => $result['mutation'],
                'attempt' => $result['attempt'],
            ],
        ], $result['status']);
    }
}
