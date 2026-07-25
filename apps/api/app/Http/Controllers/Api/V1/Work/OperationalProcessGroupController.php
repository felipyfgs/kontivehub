<?php

namespace App\Http\Controllers\Api\V1\Work;

use App\Enums\Work\ProcessStatus;
use App\Http\Controllers\Controller;
use App\Models\OperationalProcess;
use App\Services\Work\OperationalProcessGroupQuery;
use App\Support\Work\RejectClientOfficeId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OperationalProcessGroupController extends Controller
{
    public function index(Request $request, OperationalProcessGroupQuery $query): JsonResponse
    {
        $this->authorize('viewAny', OperationalProcess::class);
        RejectClientOfficeId::strip($request);

        $data = $request->validate([
            'group_by' => ['required', 'string', Rule::in(['client', 'routine'])],
            'q' => ['sometimes', 'nullable', 'string', 'max:200'],
            'competence' => ['sometimes', 'nullable', 'string', 'max:16'],
            'status' => ['sometimes', 'nullable', 'string', Rule::enum(ProcessStatus::class)],
            'client_id' => ['sometimes', 'nullable', 'integer'],
            'department_id' => ['sometimes', 'nullable', 'integer'],
            'assignee_membership_id' => ['sometimes', 'nullable', 'integer'],
            'include_archived' => ['sometimes', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'sort' => ['sometimes', 'nullable', 'string', Rule::in(OperationalProcessGroupQuery::SORT_WHITELIST)],
            'direction' => ['sometimes', 'nullable', 'string', Rule::in(['asc', 'desc'])],
        ]);

        $paginator = $query->paginate([
            ...$data,
            'include_archived' => $request->boolean('include_archived'),
        ]);

        return response()->json([
            'data' => array_values($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }
}
