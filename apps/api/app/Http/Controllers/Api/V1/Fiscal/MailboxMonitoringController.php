<?php

namespace App\Http\Controllers\Api\V1\Fiscal;

use App\Actions\Fiscal\Mutations\OperateMailboxMonitoringAction;
use App\Actions\Fiscal\PreviewMailboxDetailAction;
use App\Actions\Fiscal\ViewMailboxMonitoringAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Fiscal\Monitoring\PreviewMailboxDetailRequest;
use App\Http\Requests\Fiscal\Monitoring\ViewMailboxMonitoringRequest;
use App\Http\Requests\Fiscal\Mutations\ConfirmMailboxSyncRequest;
use App\Http\Requests\Fiscal\Mutations\EnqueueMailboxDetailRequest;
use App\Http\Requests\Fiscal\Mutations\PreviewMailboxSyncRequest;
use App\Http\Requests\Fiscal\Mutations\UpdateMailboxMonitoringSettingsRequest;
use App\Http\Resources\Fiscal\MailboxDetailPreviewResource;
use App\Http\Resources\Fiscal\MailboxMonitoringOverviewResource;
use App\Support\CurrentTenant;
use Illuminate\Http\JsonResponse;

final class MailboxMonitoringController extends Controller
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly OperateMailboxMonitoringAction $operations,
    ) {}

    public function show(
        ViewMailboxMonitoringRequest $request,
        ViewMailboxMonitoringAction $action,
    ): JsonResponse {
        $tenant = $this->currentTenant->tenant();

        return (new MailboxMonitoringOverviewResource(
            $action->handle($tenant),
        ))->response()->header('Cache-Control', 'no-store');
    }

    public function update(UpdateMailboxMonitoringSettingsRequest $request): JsonResponse
    {
        return (new MailboxMonitoringOverviewResource(
            $this->operations->updateSettings($request->settingsData()),
        ))->response()
            ->setStatusCode(200)
            ->header('Cache-Control', 'no-store');
    }

    public function preview(PreviewMailboxSyncRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->operations->preview($request->forceAll()),
        ])->header('Cache-Control', 'no-store');
    }

    public function sync(ConfirmMailboxSyncRequest $request): JsonResponse
    {
        $result = $this->operations->confirm($request->syncData());

        return response()->json(['data' => $result['data']], $result['status']);
    }

    public function detailPreview(
        PreviewMailboxDetailRequest $request,
        int $message,
        PreviewMailboxDetailAction $action,
    ): JsonResponse {
        $tenant = $this->currentTenant->tenant();
        $preview = $action->handle($tenant, $message);
        if ($preview === null) {
            return response()->json(['message' => 'Mensagem não encontrada.'], 404);
        }

        return (new MailboxDetailPreviewResource($preview))
            ->response()
            ->header('Cache-Control', 'no-store');
    }

    public function detail(EnqueueMailboxDetailRequest $request, int $message): JsonResponse
    {
        $result = $this->operations->enqueueDetail($message);

        return response()->json(['data' => $result['data']], $result['status']);
    }
}
