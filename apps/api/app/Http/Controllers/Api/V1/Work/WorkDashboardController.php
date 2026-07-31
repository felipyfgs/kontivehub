<?php

namespace App\Http\Controllers\Api\V1\Work;

use App\Actions\Work\PrepareWorkExportDownloadAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Work\CreateWorkExportRequest;
use App\Http\Requests\Work\DownloadWorkExportRequest;
use App\Http\Requests\Work\ListWorkCalendarDayRequest;
use App\Http\Requests\Work\ListWorkCalendarRequest;
use App\Http\Requests\Work\ViewWorkExportRequest;
use App\Http\Requests\Work\ViewWorkKpisRequest;
use App\Http\Resources\WorkCalendarDayCollection;
use App\Http\Resources\WorkCalendarResource;
use App\Http\Resources\WorkExportResource;
use App\Http\Resources\WorkKpiResource;
use App\Models\WorkExport;
use App\Services\Work\CalendarQuery;
use App\Services\Work\ExportService;
use App\Services\Work\KpiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WorkDashboardController extends Controller
{
    public function kpis(
        ViewWorkKpisRequest $request,
        KpiQuery $query,
    ): JsonResponse {
        return (new WorkKpiResource($query->build()))->response();
    }

    public function calendar(
        ListWorkCalendarRequest $request,
        CalendarQuery $query,
    ): JsonResponse {
        return (new WorkCalendarResource(
            $query->interval($request->filters()),
        ))->response();
    }

    public function calendarDay(
        ListWorkCalendarDayRequest $request,
        CalendarQuery $query,
    ): JsonResponse {
        return (new WorkCalendarDayCollection(
            $query->day($request->filters()),
        ))->response();
    }

    public function createExport(
        CreateWorkExportRequest $request,
        ExportService $service,
    ): JsonResponse {
        return (new WorkExportResource(
            $service->create($request->filters()),
        ))->response()->setStatusCode(201);
    }

    public function showExport(
        ViewWorkExportRequest $request,
        WorkExport $export,
    ): JsonResponse {
        return (new WorkExportResource($export))->response();
    }

    public function downloadExport(
        DownloadWorkExportRequest $request,
        WorkExport $export,
        PrepareWorkExportDownloadAction $action,
    ): StreamedResponse {
        $download = $action->execute($export);

        return Storage::disk('local')->download(
            $download->storagePath,
            $download->filename,
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }
}
