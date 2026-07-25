<?php

namespace App\Http\Controllers\Api\V1\Fiscal;

use App\Contracts\SecureObjectStore;
use App\Enums\TenantPermission;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureOfficeContext;
use App\Http\Resources\Fiscal\MeiAutomationAttemptResource;
use App\Models\User;
use App\Services\Authorization\TenantAuthorization;
use App\Services\MeiAutomation\MeiAutomationAttemptRepository;
use App\Support\CurrentOffice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class MeiAutomationAttemptController extends Controller
{
    public function __construct(
        private readonly CurrentOffice $currentOffice,
        private readonly TenantAuthorization $authorization,
        private readonly MeiAutomationAttemptRepository $attempts,
        private readonly SecureObjectStore $objects,
    ) {}

    public function show(Request $request, int $attempt): JsonResponse
    {
        if ($request->attributes->get(EnsureOfficeContext::CLIENT_OFFICE_ID_SUPPLIED) === true) {
            return response()->json([
                'message' => 'office_id não é aceito; o tenant vem da sessão autenticada.',
            ], 422);
        }

        $office = $this->currentOffice->office();
        $model = $this->attempts->findForOffice((int) $office->id, $attempt);
        $actor = $request->user();
        if (! $actor instanceof User
            || ! $this->authorization->allows($actor, TenantPermission::FiscalMonitoringView, $model)) {
            abort(403, 'Ação não autorizada.');
        }

        return (new MeiAutomationAttemptResource($model))->response();
    }

    public function download(Request $request, int $attempt, string $artifact): StreamedResponse
    {
        $office = $this->currentOffice->office();
        $model = $this->attempts->findForOffice((int) $office->id, $attempt);
        $actor = $request->user();
        if (! $actor instanceof User
            || ! $this->authorization->allows($actor, TenantPermission::FiscalMonitoringView, $model)) {
            abort(403, 'Ação não autorizada.');
        }

        $descriptor = collect($model->vault_artifacts ?? [])->first(
            static fn (mixed $item): bool => is_array($item) && ($item['id'] ?? null) === $artifact,
        );
        if (! is_array($descriptor)
            || ! is_string($descriptor['object_id'] ?? null)
            || ! is_string($descriptor['content_type'] ?? null)
            || ! is_string($descriptor['sha256'] ?? null)) {
            abort(404, 'Artefato não encontrado.');
        }

        $bytes = $this->objects->get($descriptor['object_id'], [
            'purpose' => 'MEI_PORTAL_ARTIFACT',
            'office_id' => (int) $model->office_id,
            'client_id' => (int) $model->client_id,
            'attempt_id' => (int) $model->id,
            'artifact_id' => $artifact,
            'content_type' => $descriptor['content_type'],
            'sha256' => $descriptor['sha256'],
        ]);
        $name = basename((string) ($descriptor['name'] ?? 'artefato-mei'));
        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'artefato-mei';
        }

        return response()->streamDownload(static function () use ($bytes): void {
            echo $bytes;
        }, $name, [
            'Content-Type' => $descriptor['content_type'],
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
