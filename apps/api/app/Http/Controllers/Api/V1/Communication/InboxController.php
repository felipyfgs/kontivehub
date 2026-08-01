<?php

namespace App\Http\Controllers\Api\V1\Communication;

use App\Actions\Communication\CreateInboxAction;
use App\Actions\Communication\DeleteInboxAction;
use App\Actions\Communication\ReplaceInboxMembersAction;
use App\Actions\Communication\RevokeInboxAction;
use App\Actions\Communication\StartInboxPairingAction;
use App\Actions\Communication\UpdateInboxAction;
use App\Actions\Communication\UpdateTenantSettingsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Communication\ListInboxesRequest;
use App\Http\Requests\Communication\ManageInboxRequest;
use App\Http\Requests\Communication\ReplaceInboxMembersRequest;
use App\Http\Requests\Communication\StoreInboxRequest;
use App\Http\Requests\Communication\UpdateInboxRequest;
use App\Http\Requests\Communication\UpdateTenantSettingsRequest;
use App\Http\Resources\Communication\InboxCollection;
use App\Http\Resources\Communication\InboxCommandResource;
use App\Http\Resources\Communication\InboxMembersResource;
use App\Http\Resources\Communication\InboxPairingResource;
use App\Http\Resources\Communication\InboxResource;
use App\Http\Resources\Communication\TenantSettingsResource;
use App\Models\CommunicationInbox;
use App\Services\Communication\Inbox\InboxQuery;
use Illuminate\Http\JsonResponse;

final class InboxController extends Controller
{
    public function __construct(
        private readonly InboxQuery $query,
        private readonly CreateInboxAction $createInbox,
        private readonly UpdateInboxAction $updateInbox,
        private readonly UpdateTenantSettingsAction $updateSettings,
        private readonly StartInboxPairingAction $startInboxPairing,
        private readonly RevokeInboxAction $revokeInbox,
        private readonly ReplaceInboxMembersAction $replaceInboxMembers,
        private readonly DeleteInboxAction $deleteInbox,
    ) {}

    public function index(
        ListInboxesRequest $request,
    ): InboxCollection {
        return new InboxCollection(
            $this->query->index($request->actor()),
        );
    }

    public function store(StoreInboxRequest $request): JsonResponse
    {
        return (new InboxResource(
            $this->createInbox->handle($request->inboxData()),
        ))->response()->setStatusCode(201);
    }

    public function update(
        UpdateInboxRequest $request,
        CommunicationInbox $inbox,
    ): JsonResponse {
        return (new InboxResource(
            $this->updateInbox->handle($inbox, $request->inboxData()),
        ))->response();
    }

    public function updateTenantSettings(
        UpdateTenantSettingsRequest $request,
    ): JsonResponse {
        return (new TenantSettingsResource(
            $this->updateSettings->handle($request->settingsData()),
        ))->response();
    }

    public function startPairing(
        ManageInboxRequest $request,
        CommunicationInbox $inbox,
    ): JsonResponse {
        return (new InboxPairingResource(
            $this->startInboxPairing->handle($inbox),
        ))->response()->setStatusCode(202);
    }

    public function revoke(
        ManageInboxRequest $request,
        CommunicationInbox $inbox,
    ): JsonResponse {
        return (new InboxCommandResource(
            $this->revokeInbox->handle($inbox),
        ))->response()->setStatusCode(202);
    }

    public function replaceMembers(
        ReplaceInboxMembersRequest $request,
        CommunicationInbox $inbox,
    ): JsonResponse {
        return (new InboxMembersResource(
            $this->replaceInboxMembers->handle($inbox, $request->membersData()),
        ))->response();
    }

    public function destroy(
        ManageInboxRequest $request,
        CommunicationInbox $inbox,
    ): JsonResponse {
        return (new InboxCommandResource(
            $this->deleteInbox->handle($inbox),
        ))->response()->setStatusCode(202);
    }
}
