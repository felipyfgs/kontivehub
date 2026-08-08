<?php

namespace App\Http\Controllers\Api\V1\Communication;

use App\Actions\Communication\SaveSharedContactAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Communication\SaveSharedContactRequest;
use App\Http\Resources\Communication\ContactResource;
use App\Models\CommunicationConversation;
use App\Models\CommunicationMessage;
use Illuminate\Http\JsonResponse;

final class SharedContactController extends Controller
{
    public function save(
        SaveSharedContactRequest $request,
        CommunicationConversation $conversation,
        CommunicationMessage $message,
        int $contactIndex,
        SaveSharedContactAction $action,
    ): JsonResponse {
        $result = $action->handle($conversation, $message, $contactIndex, $request->phoneIndex());

        return response()->json([
            'data' => [
                'outcome' => $result['outcome'],
                'contact' => (new ContactResource($result['contact']))->resolve($request),
            ],
        ], $result['outcome'] === 'created' ? 201 : 200);
    }
}
