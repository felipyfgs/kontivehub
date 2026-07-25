<?php

namespace App\Http\Controllers\Api\V1\Fiscal;

use App\Enums\TenantPermission;
use App\Http\Controllers\Controller;
use App\Models\MailboxClientSyncState;
use App\Models\User;
use App\Services\Authorization\TenantAuthorization;
use App\Services\Integra\Mailbox\MailboxAccessService;
use App\Services\Integra\Mailbox\MailboxQueryService;
use App\Services\Integra\Mailbox\MailboxTriageService;
use App\Support\CurrentOffice;
use App\Support\FeatureFlags;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MailboxMessageController extends Controller
{
    public function __construct(
        private readonly CurrentOffice $currentOffice,
        private readonly MailboxQueryService $queries,
        private readonly MailboxAccessService $access,
        private readonly MailboxTriageService $triage,
        private readonly TenantAuthorization $authorization,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->assertCanRead();
        $office = $this->currentOffice->office();

        $perPage = min(100, max(1, (int) $request->query('per_page', 50)));
        $clientId = $request->query('client_id');
        $triage = $request->query('triage_status');

        $page = $this->queries->messages(
            $office,
            $perPage,
            is_numeric($clientId) ? (int) $clientId : null,
            is_string($triage) ? $triage : null,
        );
        $page->getCollection()->transform(fn ($m) => $m->toListArray());

        return response()->json($page);
    }

    public function show(int $message): JsonResponse
    {
        $this->assertCanRead();
        $office = $this->currentOffice->office();
        $model = $this->queries->message($office, $message);
        if ($model === null) {
            return response()->json(['message' => 'Mensagem não encontrada.'], 404);
        }

        $result = $this->access->view($office, $model, request()->user());

        return response()->json([
            'data' => $result['message']->toDetailArray(),
            'meta' => [
                'official_read_unchanged' => $result['official_read_unchanged'],
                'triage_status' => $result['message']->triage_status?->value,
            ],
        ]);
    }

    public function triage(Request $request, int $message): JsonResponse
    {
        $this->assertCanWriteTriage();
        $office = $this->currentOffice->office();
        $model = $this->queries->message($office, $message);
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
            $office,
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
        $office = $this->currentOffice->office();
        $model = $this->queries->message($office, $message);
        if ($model === null) {
            return response()->json(['message' => 'Mensagem não encontrada.'], 404);
        }

        try {
            $file = $this->access->downloadBody($office, $model, request()->user());
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
        $office = $this->currentOffice->office();
        $model = $this->queries->message($office, $message);
        if ($model === null) {
            return response()->json(['message' => 'Mensagem não encontrada.'], 404);
        }

        $att = $this->queries->attachment($office, $message, $attachment);
        if ($att === null) {
            return response()->json(['message' => 'Anexo não encontrado.'], 404);
        }

        try {
            $file = $this->access->downloadAttachment($office, $model, $att, request()->user());
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

    public function state(Request $request): JsonResponse
    {
        $this->assertCanRead();
        $office = $this->currentOffice->office();
        $clientId = $request->query('client_id');
        if (! is_numeric($clientId)) {
            return response()->json(['message' => 'client_id obrigatório.'], 422);
        }

        $state = $this->queries->state($office, (int) $clientId);
        $sync = MailboxClientSyncState::query()->withoutGlobalScopes()
            ->where('office_id', $office->id)->where('client_id', (int) $clientId)->first();
        if ($state === null) {
            return response()->json([
                'data' => [
                    'office_id' => $office->id,
                    'client_id' => (int) $clientId,
                    'dte' => ['status' => 'UNKNOWN', 'source' => null, 'observed_at' => null],
                    'messages' => [
                        'status' => 'UNKNOWN',
                        'source' => null,
                        'observed_at' => null,
                        'official_unread_count' => null,
                        'stored_message_count' => 0,
                    ],
                    'monitoring' => $this->syncStateArray($sync),
                ],
            ]);
        }

        return response()->json(['data' => array_merge($state->toPublicArray(), [
            'monitoring' => $this->syncStateArray($sync),
        ])]);
    }

    public function alerts(Request $request): JsonResponse
    {
        $this->assertCanRead();
        $office = $this->currentOffice->office();
        $perPage = min(100, max(1, (int) $request->query('per_page', 50)));
        $activeOnly = filter_var($request->query('active_only', true), FILTER_VALIDATE_BOOL);

        $page = $this->queries->alerts($office, $perPage, $activeOnly);
        $page->getCollection()->transform(fn ($a) => $a->toPublicArray());

        return response()->json($page);
    }

    private function assertCanRead(): void
    {
        $actor = request()->user();
        if (! $actor instanceof User
            || ! $this->authorization->allows($actor, TenantPermission::OperationsView)) {
            abort(403, 'Sem permissão para consultar a Caixa Postal.');
        }

        $office = $this->currentOffice->office();
        if ($office === null || ! FeatureFlags::isModuleEnabled('mailbox', (int) $office->id)) {
            abort(403, 'Módulo Caixa Postal não disponível.');
        }
    }

    /** @return array<string,mixed> */
    private function syncStateArray(?MailboxClientSyncState $state): array
    {
        return [
            'status' => match (true) {
                $state === null || $state->bootstrap_completed_at === null => 'NEVER_SYNCED',
                $state->last_error_code !== null => 'FAILED',
                $state->authorization_status === 'DENIED' => 'BLOCKED',
                $state->pending_event_date !== null => 'PENDING_RECONCILIATION',
                default => 'HEALTHY',
            },
            'bootstrap_completed_at' => $state?->bootstrap_completed_at?->toIso8601String(),
            'last_event_observed_date' => $state?->last_event_observed_date?->toDateString(),
            'pending_event_date' => $state?->pending_event_date?->toDateString(),
            'last_reconciled_event_date' => $state?->last_reconciled_event_date?->toDateString(),
            'last_paid_check_at' => $state?->last_list_at?->toIso8601String(),
            'last_full_reconciliation_at' => $state?->last_full_reconciliation_at?->toIso8601String(),
            'authorization_status' => $state?->authorization_status ?? 'UNKNOWN',
            'block_code' => $state?->last_error_code,
        ];
    }

    private function assertCanWriteTriage(): void
    {
        $this->assertCanRead();

        $actor = request()->user();
        if (! $actor instanceof User
            || ! $this->authorization->allows($actor, TenantPermission::OperationsTriage)) {
            abort(403, 'Sem permissão para realizar a triagem operacional.');
        }

        $office = $this->currentOffice->office();
        if ($office === null || ! FeatureFlags::isMutatingEnabled('mailbox', (int) $office->id)) {
            abort(403, 'Mutação de triagem não habilitada.');
        }
    }
}
