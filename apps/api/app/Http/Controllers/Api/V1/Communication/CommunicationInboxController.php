<?php

namespace App\Http\Controllers\Api\V1\Communication;

use App\Actions\Communication\CreateCommunicationInboxAction;
use App\Actions\Communication\DeleteCommunicationInboxAction;
use App\Actions\Communication\ReplaceCommunicationInboxMembersAction;
use App\Actions\Communication\RevokeCommunicationInboxAction;
use App\Actions\Communication\StartCommunicationInboxPairingAction;
use App\Actions\Communication\UpdateCommunicationInboxAction;
use App\Actions\Communication\UpdateCommunicationTenantSettingsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Communication\ListCommunicationInboxesRequest;
use App\Http\Requests\Communication\ManageCommunicationInboxRequest;
use App\Http\Requests\Communication\ReplaceCommunicationInboxMembersRequest;
use App\Http\Requests\Communication\StoreInboxRequest;
use App\Http\Requests\Communication\UpdateCommunicationTenantSettingsRequest;
use App\Http\Requests\Communication\UpdateInboxRequest;
use App\Http\Resources\Communication\CommunicationInboxCollection;
use App\Http\Resources\Communication\CommunicationInboxCommandResource;
use App\Http\Resources\Communication\CommunicationInboxMembersResource;
use App\Http\Resources\Communication\CommunicationInboxPairingResource;
use App\Http\Resources\Communication\CommunicationInboxResource;
use App\Http\Resources\Communication\CommunicationTenantSettingsResource;
use App\Models\CommunicationInbox;
use App\Services\Communication\Inbox\CommunicationInboxQuery;
use Illuminate\Http\JsonResponse;

final class CommunicationInboxController extends Controller
{
    public function __construct(
        private readonly CommunicationInboxQuery $query,
        private readonly CreateCommunicationInboxAction $createInbox,
        private readonly UpdateCommunicationInboxAction $updateInbox,
        private readonly UpdateCommunicationTenantSettingsAction $updateSettings,
        private readonly StartCommunicationInboxPairingAction $startInboxPairing,
        private readonly RevokeCommunicationInboxAction $revokeInbox,
        private readonly ReplaceCommunicationInboxMembersAction $replaceInboxMembers,
        private readonly DeleteCommunicationInboxAction $deleteInbox,
    ) {}

    public function index(
        ListCommunicationInboxesRequest $request,
    ): CommunicationInboxCollection {
        return new CommunicationInboxCollection(
            $this->query->index($request->actor()),
        );
    }

    public function store(StoreInboxRequest $request): JsonResponse
    {
        return (new CommunicationInboxResource(
            $this->createInbox->handle($request->inboxData()),
        ))->response()->setStatusCode(201);
    }

    public function update(
        UpdateInboxRequest $request,
        CommunicationInbox $inbox,
    ): JsonResponse {
        return (new CommunicationInboxResource(
            $this->updateInbox->handle($inbox, $request->inboxData()),
        ))->response();
    }

    public function updateTenantSettings(
        UpdateCommunicationTenantSettingsRequest $request,
    ): JsonResponse {
        return (new CommunicationTenantSettingsResource(
            $this->updateSettings->handle($request->settingsData()),
        ))->response();
    }

    public function startPairing(
        ManageCommunicationInboxRequest $request,
        CommunicationInbox $inbox,
    ): JsonResponse {
        return (new CommunicationInboxPairingResource(
            $this->startInboxPairing->handle($inbox),
        ))->response()->setStatusCode(202);
    }

    public function revoke(
        ManageCommunicationInboxRequest $request,
        CommunicationInbox $inbox,
    ): JsonResponse {
        return (new CommunicationInboxCommandResource(
            $this->revokeInbox->handle($inbox),
        ))->response()->setStatusCode(202);
    }

    public function replaceMembers(
        ReplaceCommunicationInboxMembersRequest $request,
        CommunicationInbox $inbox,
    ): JsonResponse {
        return (new CommunicationInboxMembersResource(
            $this->replaceInboxMembers->handle($inbox, $request->membersData()),
        ))->response();
    }

    public function destroy(
        ManageCommunicationInboxRequest $request,
        CommunicationInbox $inbox,
    ): JsonResponse {
        return (new CommunicationInboxCommandResource(
            $this->deleteInbox->handle($inbox),
        ))->response()->setStatusCode(202);
    }
}
