<?php

namespace App\Http\Controllers\Api\V1\Communication;

use App\Actions\Communication\ExecuteInboxGatewayAction;
use App\DTO\Communication\GatewayOperationData;
use App\Enums\Communication\GatewayCommandType;
use App\Enums\Communication\GatewayQueryType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Communication\ConfirmInboxPasskeyRequest;
use App\Http\Requests\Communication\ManageInboxRequest;
use App\Http\Requests\Communication\MarkInboxStateCleanRequest;
use App\Http\Requests\Communication\PairInboxPhoneRequest;
use App\Http\Requests\Communication\QueryInboxProfilePictureRequest;
use App\Http\Requests\Communication\QueryInboxQrLinkRequest;
use App\Http\Requests\Communication\QueryInboxUsersRequest;
use App\Http\Requests\Communication\ResolveInboxLinkRequest;
use App\Http\Requests\Communication\RespondInboxPasskeyRequest;
use App\Http\Requests\Communication\TenantScopedRequest;
use App\Http\Requests\Communication\UpdateInboxBlocklistRequest;
use App\Http\Requests\Communication\UpdateInboxDisappearingRequest;
use App\Http\Requests\Communication\UpdateInboxPassiveRequest;
use App\Http\Requests\Communication\UpdateInboxPresenceRequest;
use App\Http\Requests\Communication\UpdateInboxPrivacyRequest;
use App\Http\Resources\Communication\GatewayCommandResource;
use App\Http\Resources\Communication\GatewayQueryResource;
use App\Models\CommunicationInbox;
use Illuminate\Http\JsonResponse;

/** Controles administrativos do device ligado a uma inbox do Tenant atual. */
final class InboxGatewayController extends Controller
{
    public function __construct(
        private readonly ExecuteInboxGatewayAction $gateway,
    ) {}

    public function sessionStatus(
        ManageInboxRequest $request,
        CommunicationInbox $inbox,
    ): JsonResponse {
        return (new GatewayQueryResource(
            $this->gateway->sessionStatus($request->actor(), $inbox),
        ))->response();
    }

    public function connect(
        ManageInboxRequest $request,
        CommunicationInbox $inbox,
    ): JsonResponse {
        return $this->command(
            $request,
            $inbox,
            GatewayCommandType::ConnectSession,
        );
    }

    public function disconnect(
        ManageInboxRequest $request,
        CommunicationInbox $inbox,
    ): JsonResponse {
        return $this->command(
            $request,
            $inbox,
            GatewayCommandType::DisconnectSession,
        );
    }

    public function passive(
        UpdateInboxPassiveRequest $request,
        CommunicationInbox $inbox,
    ): JsonResponse {
        return $this->command(
            $request,
            $inbox,
            GatewayCommandType::SetPassive,
            $request->gatewayData(),
        );
    }

    public function pairPhone(
        PairInboxPhoneRequest $request,
        CommunicationInbox $inbox,
    ): JsonResponse {
        return $this->command(
            $request,
            $inbox,
            GatewayCommandType::PairPhone,
            $request->gatewayData(),
        );
    }

    public function respondPasskey(
        RespondInboxPasskeyRequest $request,
        CommunicationInbox $inbox,
    ): JsonResponse {
        return $this->command(
            $request,
            $inbox,
            GatewayCommandType::RespondPasskey,
            $request->gatewayData(),
        );
    }

    public function confirmPasskey(
        ConfirmInboxPasskeyRequest $request,
        CommunicationInbox $inbox,
    ): JsonResponse {
        return $this->command(
            $request,
            $inbox,
            GatewayCommandType::ConfirmPasskey,
            $request->gatewayData(),
        );
    }

    public function globalPresence(
        UpdateInboxPresenceRequest $request,
        CommunicationInbox $inbox,
    ): JsonResponse {
        return $this->command(
            $request,
            $inbox,
            GatewayCommandType::SetPresence,
            $request->gatewayData(),
        );
    }

    public function defaultDisappearing(
        UpdateInboxDisappearingRequest $request,
        CommunicationInbox $inbox,
    ): JsonResponse {
        return $this->command(
            $request,
            $inbox,
            GatewayCommandType::SetDefaultDisappearing,
            $request->gatewayData(),
        );
    }

    public function syncState(
        ManageInboxRequest $request,
        CommunicationInbox $inbox,
    ): JsonResponse {
        return $this->command(
            $request,
            $inbox,
            GatewayCommandType::UpdateChatState,
            new GatewayOperationData(['action' => 'SYNC']),
        );
    }

    public function markStateClean(
        MarkInboxStateCleanRequest $request,
        CommunicationInbox $inbox,
    ): JsonResponse {
        return $this->command(
            $request,
            $inbox,
            GatewayCommandType::UpdateChatState,
            $request->gatewayData(),
        );
    }

    public function updateBlocklist(
        UpdateInboxBlocklistRequest $request,
        CommunicationInbox $inbox,
    ): JsonResponse {
        return $this->command(
            $request,
            $inbox,
            GatewayCommandType::UpdateBlocklist,
            $request->gatewayData(),
        );
    }

    public function updatePrivacy(
        UpdateInboxPrivacyRequest $request,
        CommunicationInbox $inbox,
    ): JsonResponse {
        return $this->command(
            $request,
            $inbox,
            GatewayCommandType::UpdatePrivacy,
            $request->gatewayData(),
        );
    }

    public function checkUsers(
        QueryInboxUsersRequest $request,
        CommunicationInbox $inbox,
    ): JsonResponse {
        return $this->query(
            $request,
            $inbox,
            GatewayQueryType::CheckUsers,
            $request->gatewayData(),
        );
    }

    public function userInfo(
        QueryInboxUsersRequest $request,
        CommunicationInbox $inbox,
    ): JsonResponse {
        return $this->query(
            $request,
            $inbox,
            GatewayQueryType::UserInfo,
            $request->gatewayData(),
        );
    }

    public function businessProfiles(
        QueryInboxUsersRequest $request,
        CommunicationInbox $inbox,
    ): JsonResponse {
        return $this->query(
            $request,
            $inbox,
            GatewayQueryType::BusinessProfile,
            $request->gatewayData(),
        );
    }

    public function profilePicture(
        QueryInboxProfilePictureRequest $request,
        CommunicationInbox $inbox,
    ): JsonResponse {
        return (new GatewayQueryResource(
            $this->gateway->scheduleProfilePicture($inbox, $request->gatewayData()),
        ))->response();
    }

    public function contactQrLink(
        QueryInboxQrLinkRequest $request,
        CommunicationInbox $inbox,
    ): JsonResponse {
        return $this->query(
            $request,
            $inbox,
            GatewayQueryType::ContactQrLink,
            $request->gatewayData(),
        );
    }

    public function resolveContactQr(
        ResolveInboxLinkRequest $request,
        CommunicationInbox $inbox,
    ): JsonResponse {
        return $this->query(
            $request,
            $inbox,
            GatewayQueryType::ResolveContactQr,
            $request->gatewayData(),
        );
    }

    public function resolveBusinessLink(
        ResolveInboxLinkRequest $request,
        CommunicationInbox $inbox,
    ): JsonResponse {
        return $this->query(
            $request,
            $inbox,
            GatewayQueryType::ResolveBusinessLink,
            $request->gatewayData(),
        );
    }

    public function blocklist(
        ManageInboxRequest $request,
        CommunicationInbox $inbox,
    ): JsonResponse {
        return $this->query(
            $request,
            $inbox,
            GatewayQueryType::Blocklist,
        );
    }

    public function privacy(
        ManageInboxRequest $request,
        CommunicationInbox $inbox,
    ): JsonResponse {
        return $this->query(
            $request,
            $inbox,
            GatewayQueryType::PrivacySettings,
        );
    }

    private function command(
        TenantScopedRequest $request,
        CommunicationInbox $inbox,
        GatewayCommandType $type,
        ?GatewayOperationData $data = null,
    ): JsonResponse {
        return (new GatewayCommandResource(
            $this->gateway->command(
                $request->actor(),
                $inbox,
                $type,
                $data ?? new GatewayOperationData,
            ),
        ))->response()->setStatusCode(202);
    }

    private function query(
        TenantScopedRequest $request,
        CommunicationInbox $inbox,
        GatewayQueryType $type,
        ?GatewayOperationData $data = null,
    ): JsonResponse {
        return (new GatewayQueryResource(
            $this->gateway->query(
                $request->actor(),
                $inbox,
                $type,
                $data ?? new GatewayOperationData,
            ),
        ))->response();
    }
}
