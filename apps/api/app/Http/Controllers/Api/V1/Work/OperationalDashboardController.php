<?php

namespace App\Http\Controllers\Api\V1\Work;

use App\Http\Controllers\Controller;
use App\Models\OperationalExport;
use App\Models\OperationalTask;
use App\Services\Work\OperationalCalendarQuery;
use App\Services\Work\OperationalExportService;
use App\Services\Work\OperationalKpiQuery;
use App\Support\Work\RejectClientOfficeId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OperationalDashboardController extends Controller
{
    public function kpis(OperationalKpiQuery $query): JsonResponse
    {
        $this->authorize('viewAny', OperationalTask::class);

        return response()->json(['data' => $query->build()]);
    }

    public function calendar(Request $request, OperationalCalendarQuery $query): JsonResponse
    {
        $this->authorize('viewAny', OperationalTask::class);
        RejectClientOfficeId::strip($request);

        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d'],
            'department_id' => ['sometimes', 'nullable', 'integer'],
            'assignee_membership_id' => ['sometimes', 'nullable', 'integer'],
            'client_id' => ['sometimes', 'nullable', 'integer'],
            'status' => ['sometimes', 'nullable', 'string'],
            'risk' => ['sometimes', 'nullable', 'string'],
        ]);

        return response()->json([
            'data' => $query->interval($data),
        ]);
    }

    public function calendarDay(Request $request, OperationalCalendarQuery $query): JsonResponse
    {
        $this->authorize('viewAny', OperationalTask::class);
        RejectClientOfficeId::strip($request);

        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'department_id' => ['sometimes', 'nullable', 'integer'],
            'assignee_membership_id' => ['sometimes', 'nullable', 'integer'],
            'client_id' => ['sometimes', 'nullable', 'integer'],
            'status' => ['sometimes', 'nullable', 'string'],
            'risk' => ['sometimes', 'nullable', 'string'],
        ]);

        $paginator = $query->day($data);

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function createExport(Request $request, OperationalExportService $service): JsonResponse
    {
        $this->authorize('create', OperationalExport::class);
        RejectClientOfficeId::strip($request);

        $data = $request->validate([
            'filters' => ['sometimes', 'array'],
            'filters.status' => ['sometimes', 'nullable', 'string'],
            'filters.department_id' => ['sometimes', 'nullable', 'integer'],
            'filters.client_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        $export = $service->create($data['filters'] ?? []);

        return response()->json(['data' => $this->publicExport($export)], 201);
    }

    public function showExport(OperationalExport $export): JsonResponse
    {
        $this->authorize('view', $export);

        return response()->json(['data' => $this->publicExport($export)]);
    }

    public function downloadExport(OperationalExport $export): StreamedResponse
    {
        $this->authorize('download', $export);

        if ($export->status->value !== 'READY' || ! $export->storage_path) {
            abort(404);
        }

        $path = Storage::disk('local')->path($export->storage_path);

        return response()->streamDownload(function () use ($path): void {
            echo file_get_contents($path);
        }, 'operational-export-'.$export->id.'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function publicExport(OperationalExport $e): array
    {
        return [
            'id' => $e->id,
            'status' => $e->status->value,
            'filters_snapshot' => $e->filters_snapshot,
            'byte_size' => $e->byte_size,
            'row_count' => $e->row_count,
            'error_message' => $e->error_message,
            'expires_at' => $e->expires_at?->toIso8601String(),
            'completed_at' => $e->completed_at?->toIso8601String(),
            // storage_path omitido
        ];
    }
}
