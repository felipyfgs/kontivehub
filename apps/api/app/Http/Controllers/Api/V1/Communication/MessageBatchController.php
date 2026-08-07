<?php

namespace App\Http\Controllers\Api\V1\Communication;

use App\Actions\Communication\CreateMessageBatchAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Communication\StoreMessageBatchRequest;
use App\Http\Resources\Communication\MessageBatchResource;
use App\Models\CommunicationConversation;
use Illuminate\Http\JsonResponse;

final class MessageBatchController extends Controller
{
    public function store(
        StoreMessageBatchRequest $request,
        CommunicationConversation $conversation,
        CreateMessageBatchAction $action,
    ): JsonResponse {
        $result = $action->handle($conversation, $request->batchData());

        return (new MessageBatchResource($result->batch))
            ->response()
            ->setStatusCode($result->httpStatus);
    }
}
