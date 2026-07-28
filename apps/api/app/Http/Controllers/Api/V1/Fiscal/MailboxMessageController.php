<?php

namespace App\Http\Controllers\Api\V1\Fiscal;

use App\Actions\Fiscal\ShowMailboxStateAction;
use App\Enums\TenantPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Fiscal\Monitoring\ListMailboxAlertsRequest;
use App\Http\Requests\Fiscal\Monitoring\ListMailboxMessagesRequest;
use App\Http\Requests\Fiscal\Monitoring\ShowMailboxStateRequest;
use App\Http\Requests\Fiscal\Monitoring\ViewMailboxMessageRequest;
use App\Http\Resources\Fiscal\MailboxAlertPageResource;
use App\Http\Resources\Fiscal\MailboxMessageDetailResource;
use App\Http\Resources\Fiscal\MailboxMessagePageResource;
use App\Http\Resources\Fiscal\MailboxStateResource;
use App\Models\User;
use App\Services\Authorization\TenantAuthorization;
use App\Services\Integra\Mailbox\MailboxAccessService;
use App\Services\Integra\Mailbox\MailboxQueryService;
use App\Services\Integra\Mailbox\MailboxTriageService;
use App\Support\CurrentTenant;
use App\Support\FeatureFlags;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MailboxMessageController extends Controller
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly MailboxQueryService $queries,
        private readonly MailboxAccessService $access,
        private readonly MailboxTriageService $triage,
        private readonly TenantAuthorization $authorization,
    ) {}

    public function index(
        ListMailboxMessagesRequest $request,
    ): MailboxMessagePageResource {
        $tenant = $this->currentTenant->tenant();
        $filters = $request->filters();

        return new MailboxMessagePageResource(
            $this->queries->messages(
                $tenant,
                $filters->perPage,
                $filters->clientId,
                $filters->triageStatus,
            ),
        );
    }

    public function show(
        ViewMailboxMessageRequest $request,
        int $message,
    ): JsonResponse|MailboxMessageDetailResource {
        $tenant = $this->currentTenant->tenant();
        $model = $this->queries->message($tenant, $message);
        if ($model === null) {
            return response()->json(['message' => 'Mensagem não encontrada.'], 404);
        }

        $result = $this->access->view($tenant, $model, $request->user());

        return (new MailboxMessageDetailResource($result['message']))
            ->additional(['meta' => [
                'official_read_unchanged' => $result['official_read_unchanged'],
                'triage_status' => $result['message']->triage_status?->value,
            ]]);
    }

    public function triage(Request $request, int $message): JsonResponse
    {
        $this->assertCanWriteTriage();
        $tenant = $this->currentTenant->tenant();
        $model = $this->queries->message($tenant, $message);
        if ($model === null) {
            return response()->json(['message' => 'Mensagem não encontrada.'], 404);
        }

        $statusRaw = (string) $request->input('triage_status', '');
        try {
            $status = $this->triage->parseStatus($statusRaw);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $note = $request->input('note');
        $note = is_string($note) ? $note : null;

        $updated = $this->triage->update(
            $tenant,
            $model,
            $status,
            $request->user(),
            $note,
        );

        return response()->json([
            'data' => $updated->toDetailArray(),
            'meta' => [
                'official_read_indicator' => $updated->official_read_indicator,
            ],
        ]);
    }

    public function downloadBody(int $message): StreamedResponse|JsonResponse
    {
        $this->assertCanRead();
        $tenant = $this->currentTenant->tenant();
        $model = $this->queries->message($tenant, $message);
        if ($model === null) {
            return response()->json(['message' => 'Mensagem não encontrada.'], 404);
        }

        try {
            $file = $this->access->downloadBody($tenant, $model, request()->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        return response()->streamDownload(function () use ($file): void {
            echo $file['bytes'];
        }, $file['filename'], [
            'Content-Type' => $file['content_type'],
            'Cache-Control' => 'no-store',
        ]);
    }

    public function downloadAttachment(int $message, int $attachment): StreamedResponse|JsonResponse
    {
        $this->assertCanRead();
        $tenant = $this->currentTenant->tenant();
        $model = $this->queries->message($tenant, $message);
        if ($model === null) {
            return response()->json(['message' => 'Mensagem não encontrada.'], 404);
        }

        $att = $this->queries->attachment($tenant, $message, $attachment);
        if ($att === null) {
            return response()->json(['message' => 'Anexo não encontrado.'], 404);
        }

        try {
            $file = $this->access->downloadAttachment($tenant, $model, $att, request()->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        return response()->streamDownload(function () use ($file): void {
            echo $file['bytes'];
        }, $file['filename'], [
            'Content-Type' => $file['content_type'],
            'Cache-Control' => 'no-store',
        ]);
    }

    public function state(
        ShowMailboxStateRequest $request,
        ShowMailboxStateAction $action,
    ): MailboxStateResource {
        $tenant = $this->currentTenant->tenant();

        return new MailboxStateResource(
            $action->handle($tenant, $request->clientId()),
        );
    }

    public function alerts(
        ListMailboxAlertsRequest $request,
    ): MailboxAlertPageResource {
        $tenant = $this->currentTenant->tenant();
        $filters = $request->filters();

        return new MailboxAlertPageResource(
            $this->queries->alerts(
                $tenant,
                $filters->perPage,
                $filters->activeOnly,
            ),
        );
    }

    private function assertCanRead(): void
    {
        $actor = request()->user();
        if (! $actor instanceof User
            || ! $this->authorization->allows($actor, TenantPermission::OperationsView)) {
            abort(403, 'Sem permissão para consultar a Caixa Postal.');
        }

        $tenant = $this->currentTenant->tenant();
        if ($tenant === null || ! FeatureFlags::isModuleEnabled('mailbox', (int) $tenant->id)) {
            abort(403, 'Módulo Caixa Postal não disponível.');
        }
    }

    private function assertCanWriteTriage(): void
    {
        $this->assertCanRead();

        $actor = request()->user();
        if (! $actor instanceof User
            || ! $this->authorization->allows($actor, TenantPermission::OperationsTriage)) {
            abort(403, 'Sem permissão para realizar a triagem operacional.');
        }

        $tenant = $this->currentTenant->tenant();
        if ($tenant === null || ! FeatureFlags::isMutatingEnabled('mailbox', (int) $tenant->id)) {
            abort(403, 'Mutação de triagem não habilitada.');
        }
    }
}
