<?php

namespace App\Http\Controllers\Api\V1\Communication;

use App\Actions\Communication\AdmitConversationBulkOperationAction;
use App\Enums\Communication\ConversationBulkItemStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Communication\ListConversationBulkOperationItemsRequest;
use App\Http\Requests\Communication\StoreConversationBulkOperationRequest;
use App\Http\Requests\Communication\ViewConversationBulkOperationRequest;
use App\Http\Resources\Communication\ConversationBulkOperationItemPageResource;
use App\Http\Resources\Communication\ConversationBulkOperationResource;
use App\Models\CommunicationConversationBulkOperationItem;
use App\Services\Communication\Conversation\ConversationBulkOperationService;
use Illuminate\Http\JsonResponse;

final class ConversationBulkOperationController extends Controller
{
    public function store(
        StoreConversationBulkOperationRequest $request,
        AdmitConversationBulkOperationAction $action,
    ): JsonResponse {
        $result = $action->execute($request->admissionData());

        return (new ConversationBulkOperationResource(
            $result->operation,
        ))->response()->setStatusCode(202);
    }

    public function show(
        ViewConversationBulkOperationRequest $request,
        string $operation,
        ConversationBulkOperationService $operations,
    ): JsonResponse {
        $model = $operations->findForActor($request->actor(), $operation);

        return (new ConversationBulkOperationResource($model))->response();
    }

    public function items(
        ListConversationBulkOperationItemsRequest $request,
        string $operation,
        ConversationBulkOperationService $operations,
    ): JsonResponse {
        $model = $operations->findForActor($request->actor(), $operation);
        $query = CommunicationConversationBulkOperationItem::query()
            ->where('bulk_operation_id', $model->id)
            ->orderBy('item_index');

        $status = $request->statusFilter();
        if ($status instanceof ConversationBulkItemStatus) {
            $query->where('status', $status);
        }

        return (new ConversationBulkOperationItemPageResource(
            $query->paginate(
                perPage: $request->perPage(),
                page: $request->page(),
            ),
        ))->response();
    }
}
