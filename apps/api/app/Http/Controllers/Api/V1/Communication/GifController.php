<?php

namespace App\Http\Controllers\Api\V1\Communication;

use App\Enums\Communication\OutboundCapabilityUnavailableReason;
use App\Http\Controllers\Controller;
use App\Http\Requests\Communication\SearchGifsRequest;
use App\Models\CommunicationInbox;
use App\Services\Communication\Authorization\Access;
use App\Services\Communication\Catalog\OutboundCapabilityEvaluator;
use App\Services\Communication\Gif\GifSearchService;
use App\Support\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

final class GifController extends Controller
{
    public function search(
        SearchGifsRequest $request,
        GifSearchService $search,
        OutboundCapabilityEvaluator $capabilities,
    ): JsonResponse {
        $gif = $capabilities->feature('gif', OutboundCapabilityUnavailableReason::GifPlaybackBuilderUnimplemented);
        if (! $gif->enabled || config('communication.gif_provider.driver', 'disabled') === 'disabled') {
            return response()->json(['code' => 'GIF_PROVIDER_DISABLED', 'message' => 'Busca remota de GIF indisponível.'], 503);
        }

        try {
            $results = $search->search(
                $request->user(),
                $request->inbox(),
                trim((string) $request->validated('q')),
                (int) ($request->validated('limit') ?? 20),
            );
        } catch (RuntimeException) {
            return response()->json(['code' => 'GIF_PROVIDER_UNAVAILABLE', 'message' => 'Busca remota de GIF indisponível.'], 503);
        }

        return response()->json(['data' => $results])->header('Cache-Control', 'private, no-store');
    }

    public function preview(
        string $token,
        CurrentTenant $currentTenant,
        Access $access,
        GifSearchService $search,
    ): Response {
        $actor = request()->user();
        if ($actor === null || preg_match('/^[A-Za-z0-9]{40}$/', $token) !== 1) {
            abort(404);
        }
        $asset = Cache::get('communication:gif-asset:'.$currentTenant->id().':'.$token);
        $inboxId = is_array($asset) ? (int) ($asset['inbox_id'] ?? 0) : 0;
        $inbox = $inboxId > 0 ? CommunicationInbox::query()->find($inboxId) : null;
        $url = is_array($asset) ? (string) ($asset['preview_url'] ?? '') : '';
        if (! $inbox instanceof CommunicationInbox || ! $access->canView($actor, $inbox)) {
            abort(404);
        }
        try {
            $asset = $search->fetchAsset($url, true);
        } catch (RuntimeException) {
            abort(404);
        }
        if ($asset === null) {
            abort(404);
        }
        $body = $asset['body'];
        $maximum = max(1, (int) config('communication.gif_provider.preview_max_bytes', 2_097_152));
        $mime = $asset['mime'];
        if (strlen($body) > $maximum
            || ! in_array($mime, ['image/gif', 'image/webp', 'image/jpeg', 'image/png'], true)) {
            abort(404);
        }

        return response($body, 200, [
            'Content-Type' => $mime,
            'Content-Length' => (string) strlen($body),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function asset(
        string $token,
        CurrentTenant $currentTenant,
        Access $access,
        GifSearchService $search,
    ): Response|JsonResponse {
        $actor = request()->user();
        if ($actor === null || preg_match('/^[A-Za-z0-9]{40}$/', $token) !== 1) {
            abort(404);
        }
        $asset = Cache::get('communication:gif-asset:'.$currentTenant->id().':'.$token);
        $inboxId = is_array($asset) ? (int) ($asset['inbox_id'] ?? 0) : 0;
        $inbox = $inboxId > 0 ? CommunicationInbox::query()->find($inboxId) : null;
        $url = is_array($asset) ? (string) ($asset['media_url'] ?? '') : '';
        if (! $inbox instanceof CommunicationInbox || ! $access->canReply($actor, $inbox)) {
            abort(404);
        }

        try {
            $asset = $search->fetchAsset($url);
        } catch (RuntimeException) {
            return response()->json(['code' => 'GIF_ASSET_UNAVAILABLE'], 503);
        }
        if ($asset === null) {
            abort(404);
        }
        $maximum = max(1, (int) config('communication.media.max_bytes'));
        $body = $asset['body'];
        $mime = $asset['mime'];
        if (strlen($body) > $maximum || ! in_array($mime, ['video/mp4', 'video/webm'], true)) {
            return response()->json(['code' => 'GIF_ASSET_UNAVAILABLE'], 503);
        }

        return response($body, 200, [
            'Content-Type' => $mime,
            'Content-Length' => (string) strlen($body),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
