<?php

namespace App\Http\Controllers\Api\V1\Communication;

use App\Actions\Communication\ImportStickerAction;
use App\Enums\Communication\StickerAvailability;
use App\Enums\Communication\StickerSyncStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Communication\ImportStickerRequest;
use App\Http\Requests\Communication\ListStickerLibraryRequest;
use App\Http\Requests\Communication\RemoveStickerRequest;
use App\Http\Requests\Communication\UpdateStickerFavoriteRequest;
use App\Http\Requests\Communication\ViewStickerRequest;
use App\Http\Resources\Communication\StickerResource;
use App\Models\CommunicationInbox;
use App\Models\CommunicationStickerObservation;
use App\Models\CommunicationStickerSyncWatermark;
use App\Services\Communication\Media\MediaStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class StickerLibraryController extends Controller
{
    public function index(ListStickerLibraryRequest $request, CommunicationInbox $inbox): JsonResponse
    {
        $query = CommunicationStickerObservation::query()
            ->where('inbox_id', $inbox->id)
            ->visible()
            ->with('content')
            ->latest('last_observed_at')
            ->latest('id');
        $favorite = $request->validated('favorite');
        $query->when($favorite === 'app', fn ($query) => $query->where('app_favorite', true));
        $query->when($favorite === 'device', fn ($query) => $query->where('device_favorite', true));
        $query->when($favorite === 'any', fn ($query) => $query->where(fn ($nested) => $nested
            ->where('app_favorite', true)->orWhere('device_favorite', true)));
        $query->when($request->validated('source'), fn ($query, $source) => $query->where('source', $source));

        $response = StickerResource::collection($query->paginate($request->perPage()))->response();
        $payload = $response->getData(true);
        $watermark = CommunicationStickerSyncWatermark::query()->where('inbox_id', $inbox->id)->first();
        $status = $watermark?->status ?? StickerSyncStatus::NotObserved;
        $payload['meta']['sync_status'] = strtolower($status->value);
        $payload['meta']['sync_reason'] = $watermark?->reason_code ?? 'NO_DEVICE_OBSERVATION';
        $payload['meta']['last_observed_at'] = $watermark?->last_observed_at?->toAtomString();

        return response()->json($payload)->header('Cache-Control', 'private, no-store');
    }

    public function import(
        ImportStickerRequest $request,
        CommunicationInbox $inbox,
        ImportStickerAction $action,
    ): JsonResponse {
        return (new StickerResource($action->handle($inbox, $request->upload())))
            ->response()
            ->setStatusCode(201)
            ->header('Cache-Control', 'private, no-store');
    }

    public function preview(
        ViewStickerRequest $request,
        CommunicationStickerObservation $sticker,
        MediaStore $media,
    ) {
        $sticker->load('content');
        $content = $sticker->content;
        if ($sticker->removed_at !== null
            || $sticker->availability !== StickerAvailability::Available
            || $content === null) {
            abort(404);
        }
        try {
            $bytes = $media->readValidated(
                (string) $content->object_id_encrypted,
                (array) $content->storage_context_encrypted,
                (int) $content->size_bytes,
                (string) $content->sha256,
            );
        } catch (RuntimeException $error) {
            $sticker->forceFill([
                'availability' => StickerAvailability::Unreadable,
                'unavailable_reason' => 'PRIVATE_OBJECT_UNREADABLE',
            ])->save();
            Log::warning('communication.sticker.unavailable', [
                'tenant_id' => (int) $sticker->tenant_id,
                'inbox_id' => (int) $sticker->inbox_id,
                'sticker_id' => $sticker->public_id,
                'reason' => 'PRIVATE_OBJECT_UNREADABLE',
            ]);
            abort(409, 'A figurinha privada está temporariamente indisponível.');
        }

        return response($bytes, 200, [
            'Content-Type' => 'image/webp',
            'Content-Length' => (string) strlen($bytes),
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline; filename="sticker.webp"',
        ]);
    }

    public function favorite(
        UpdateStickerFavoriteRequest $request,
        CommunicationStickerObservation $sticker,
    ): StickerResource {
        $sticker->forceFill(['app_favorite' => (bool) $request->validated('favorite')])->save();

        return new StickerResource($sticker->load('content'));
    }

    public function remove(
        RemoveStickerRequest $request,
        CommunicationStickerObservation $sticker,
    ): JsonResponse {
        $sticker->forceFill([
            'app_favorite' => false,
            'removed_at' => now(),
        ])->save();

        return response()->json(status: 204);
    }

    public function status(ListStickerLibraryRequest $request, CommunicationInbox $inbox): JsonResponse
    {
        $watermark = CommunicationStickerSyncWatermark::query()->where('inbox_id', $inbox->id)->first();
        $status = $watermark?->status ?? StickerSyncStatus::NotObserved;

        return response()->json(['data' => [
            'status' => strtolower($status->value),
            'reason_code' => $watermark?->reason_code ?? 'NO_DEVICE_OBSERVATION',
            'last_observed_at' => $watermark?->last_observed_at?->toAtomString(),
            'complete' => false,
            'capability' => [
                'enabled' => (bool) config('communication.sticker_library.enabled', false),
                'sources' => ['LOCAL_IMPORT', 'DEVICE_RECENT', 'DEVICE_FAVORITE', 'DEVICE_MESSAGE'],
                'max_item_bytes' => (int) config('communication.sticker_library.max_item_bytes', 1_048_576),
                'max_items_per_tenant' => (int) config('communication.sticker_library.max_items_per_tenant', 500),
            ],
        ]])->header('Cache-Control', 'private, no-store');
    }
}
