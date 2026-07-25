<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TenantPermission;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Authorization\TenantAuthorization;
use App\Services\Operations\Inbox\InboxCapabilities;
use App\Services\Operations\OperationsInboxBuilder;
use App\Support\CurrentOffice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OperationsInboxController extends Controller
{
    public function __invoke(
        Request $request,
        CurrentOffice $currentOffice,
        TenantAuthorization $authorization,
        OperationsInboxBuilder $inbox,
    ): JsonResponse {
        // office_id do cliente é ignorado; sempre o escritório da sessão.
        $officeId = $currentOffice->id();
        abort_if($officeId === null, 403);

        $severity = $request->query('severity');
        $type = $request->query('type');
        $severity = is_string($severity) ? $severity : null;
        $type = is_string($type) ? $type : null;

        if ($severity !== null && $severity !== '' && ! in_array($severity, OperationsInboxBuilder::SEVERITIES, true)) {
            return response()->json(['message' => 'Severidade inválida.'], 422);
        }
        if ($type !== null && $type !== '' && ! in_array($type, OperationsInboxBuilder::TYPES, true)) {
            return response()->json(['message' => 'Tipo de item inválido.'], 422);
        }

        $limit = min(max((int) $request->query('limit', 50), 1), 100);
        $cursor = $request->query('cursor');
        $cursor = is_string($cursor) ? $cursor : null;

        $payload = $inbox->build(
            officeId: $officeId,
            capabilities: $this->capabilities($request, $authorization),
            severity: $severity,
            type: $type,
            limit: $limit,
            cursor: $cursor,
        );

        return response()->json($payload);
    }

    private function capabilities(Request $request, TenantAuthorization $authorization): InboxCapabilities
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            return new InboxCapabilities;
        }

        return new InboxCapabilities(
            canTriggerSync: $authorization->allows($actor, TenantPermission::FiscalSyncTrigger),
            canManageClients: $authorization->allows($actor, TenantPermission::ClientsManage),
        );
    }
}
