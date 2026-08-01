<?php

namespace App\Http\Controllers\Api\V1\Work;

use App\Actions\Work\PrepareExportDownloadAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Work\CreateExportRequest;
use App\Http\Requests\Work\DownloadExportRequest;
use App\Http\Requests\Work\ListCalendarDayRequest;
use App\Http\Requests\Work\ListCalendarRequest;
use App\Http\Requests\Work\ViewExportRequest;
use App\Http\Requests\Work\ViewKpisRequest;
use App\Http\Resources\Work\CalendarDayCollection;
use App\Http\Resources\Work\CalendarResource;
use App\Http\Resources\Work\ExportResource;
use App\Http\Resources\Work\KpiResource;
use App\Models\WorkExport;
use App\Services\Work\CalendarQuery;
use App\Services\Work\ExportService;
use App\Services\Work\KpiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DashboardController extends Controller
{
    public function kpis(
        ViewKpisRequest $request,
        KpiQuery $query,
    ): JsonResponse {
        return (new KpiResource($query->build()))->response();
    }

    public function calendar(
        ListCalendarRequest $request,
        CalendarQuery $query,
    ): JsonResponse {
        return (new CalendarResource(
            $query->interval($request->filters()),
        ))->response();
    }

    public function calendarDay(
        ListCalendarDayRequest $request,
        CalendarQuery $query,
    ): JsonResponse {
        return (new CalendarDayCollection(
            $query->day($request->filters()),
        ))->response();
    }

    public function createExport(
        CreateExportRequest $request,
        ExportService $service,
    ): JsonResponse {
        return (new ExportResource(
            $service->create($request->filters()),
        ))->response()->setStatusCode(201);
    }

    public function showExport(
        ViewExportRequest $request,
        WorkExport $export,
    ): JsonResponse {
        return (new ExportResource($export))->response();
    }

    public function downloadExport(
        DownloadExportRequest $request,
        WorkExport $export,
        PrepareExportDownloadAction $action,
    ): StreamedResponse {
        $download = $action->execute($export);

        return Storage::disk('local')->download(
            $download->storagePath,
            $download->filename,
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }
}
