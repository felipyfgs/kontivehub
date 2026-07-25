<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Operations\OperationsSummaryBuilder;
use App\Support\CurrentOffice;
use Illuminate\Http\JsonResponse;

class OperationsSummaryController extends Controller
{
    public function __invoke(
        CurrentOffice $currentOffice,
        OperationsSummaryBuilder $summary,
    ): JsonResponse {
        $officeId = $currentOffice->id();
        abort_if($officeId === null, 403);

        return response()->json([
            'data' => $summary->build($officeId),
        ]);
    }
}
