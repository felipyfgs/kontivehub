<?php

namespace App\Http\Controllers\Api\V1\Communication;

use App\Actions\Communication\UpsertConversationListPreferenceAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Communication\ShowConversationListPreferencesRequest;
use App\Http\Requests\Communication\UpdateConversationListPreferencesRequest;
use App\Http\Resources\Communication\ConversationListPreferenceResource;
use App\Services\Communication\Conversation\ConversationListPreferenceService;
use Illuminate\Http\JsonResponse;

final class ConversationListPreferenceController extends Controller
{
    public function show(
        ShowConversationListPreferencesRequest $request,
        ConversationListPreferenceService $preferences,
    ): JsonResponse {
        return (new ConversationListPreferenceResource(
            $preferences->resolve($request->actor()),
        ))->response();
    }

    public function update(
        UpdateConversationListPreferencesRequest $request,
        UpsertConversationListPreferenceAction $action,
    ): JsonResponse {
        $preference = $action->handle($request->actor(), $request->preferenceData());

        return (new ConversationListPreferenceResource([
            'status' => (string) $preference->status,
            'sort_by' => $preference->sort_by->value,
            'is_default' => false,
        ]))->response();
    }
}
