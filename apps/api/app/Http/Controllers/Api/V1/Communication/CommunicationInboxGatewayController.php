<?php

namespace App\Http\Controllers\Api\V1\Communication;

use App\Actions\Communication\ExecuteCommunicationInboxGatewayAction;
use App\DTO\Communication\CommunicationGatewayOperationData;
use App\Enums\Communication\GatewayCommandType;
use App\Enums\Communication\GatewayQueryType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Communication\CommunicationRequest;
use App\Http\Requests\Communication\ConfirmCommunicationInboxPasskeyRequest;
use App\Http\Requests\Communication\ManageCommunicationInboxRequest;
use App\Http\Requests\Communication\MarkCommunicationInboxStateCleanRequest;
use App\Http\Requests\Communication\PairCommunicationInboxPhoneRequest;
use App\Http\Requests\Communication\QueryCommunicationInboxProfilePictureRequest;
use App\Http\Requests\Communication\QueryCommunicationInboxQrLinkRequest;
use App\Http\Requests\Communication\QueryCommunicationInboxUsersRequest;
use App\Http\Requests\Communication\ResolveCommunicationInboxLinkRequest;
use App\Http\Requests\Communication\RespondCommunicationInboxPasskeyRequest;
use App\Http\Requests\Communication\UpdateCommunicationInboxBlocklistRequest;
use App\Http\Requests\Communication\UpdateCommunicationInboxDisappearingRequest;
use App\Http\Requests\Communication\UpdateCommunicationInboxPassiveRequest;
use App\Http\Requests\Communication\UpdateCommunicationInboxPresenceRequest;
use App\Http\Requests\Communication\UpdateCommunicationInboxPrivacyRequest;
use App\Http\Resources\Communication\CommunicationGatewayCommandResource;
use App\Http\Resources\Communication\CommunicationGatewayQueryResource;
use App\Models\CommunicationInbox;
use Illuminate\Http\JsonResponse;

/** Controles administrativos do device ligado a uma inbox do Tenant atual. */
final class CommunicationInboxGatewayController extends Controller
{
    public function __construct(
        private readonly ExecuteCommunicationInboxGatewayAction $gateway,
    ) {}

    public function sessionStatus(
        ManageCommunicationInboxRequest $request,
        CommunicationInbox $inbox,
    ): JsonResponse {
        return (new CommunicationGatewayQueryResource(
            $this->gateway->sessionStatus($request->actor(), $inbox),
        ))->response();
    }

    public function connect(
        ManageCommunicationInboxRequest $request,
        CommunicationInbox $inbox,
    ): JsonResponse {
        return $this->command(
            $request,
            $inbox,
            GatewayCommandType::ConnectSession,
        );
    }

    public function disconnect(
        ManageCommunicationInboxRequest $request,
        CommunicationInbox $inbox,
    ): JsonResponse {
        return $this->command(
            $request,
            $inbox,
            GatewayCommandType::DisconnectSession,
        );
    }

    public function passive(
        UpdateCommunicationInboxPassiveRequest $request,
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
        PairCommunicationInboxPhoneRequest $request,
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
        RespondCommunicationInboxPasskeyRequest $request,
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
        ConfirmCommunicationInboxPasskeyRequest $request,
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
        UpdateCommunicationInboxPresenceRequest $request,
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
        UpdateCommunicationInboxDisappearingRequest $request,
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
        ManageCommunicationInboxRequest $request,
        CommunicationInbox $inbox,
    ): JsonResponse {
        return $this->command(
            $request,
            $inbox,
            GatewayCommandType::UpdateChatState,
            new CommunicationGatewayOperationData(['action' => 'SYNC']),
        );
    }

    public function markStateClean(
        MarkCommunicationInboxStateCleanRequest $request,
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
        UpdateCommunicationInboxBlocklistRequest $request,
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
        UpdateCommunicationInboxPrivacyRequest $request,
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
        QueryCommunicationInboxUsersRequest $request,
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
        QueryCommunicationInboxUsersRequest $request,
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
        QueryCommunicationInboxUsersRequest $request,
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
        QueryCommunicationInboxProfilePictureRequest $request,
        CommunicationInbox $inbox,
    ): JsonResponse {
        return (new CommunicationGatewayQueryResource(
            $this->gateway->scheduleProfilePicture($inbox, $request->gatewayData()),
        ))->response();
    }

    public function contactQrLink(
        QueryCommunicationInboxQrLinkRequest $request,
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
        ResolveCommunicationInboxLinkRequest $request,
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
        ResolveCommunicationInboxLinkRequest $request,
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
        ManageCommunicationInboxRequest $request,
        CommunicationInbox $inbox,
    ): JsonResponse {
        return $this->query(
            $request,
            $inbox,
            GatewayQueryType::Blocklist,
        );
    }

    public function privacy(
        ManageCommunicationInboxRequest $request,
        CommunicationInbox $inbox,
    ): JsonResponse {
        return $this->query(
            $request,
            $inbox,
            GatewayQueryType::PrivacySettings,
        );
    }

    private function command(
        CommunicationRequest $request,
        CommunicationInbox $inbox,
        GatewayCommandType $type,
        ?CommunicationGatewayOperationData $data = null,
    ): JsonResponse {
        return (new CommunicationGatewayCommandResource(
            $this->gateway->command(
                $request->actor(),
                $inbox,
                $type,
                $data ?? new CommunicationGatewayOperationData,
            ),
        ))->response()->setStatusCode(202);
    }

    private function query(
        CommunicationRequest $request,
        CommunicationInbox $inbox,
        GatewayQueryType $type,
        ?CommunicationGatewayOperationData $data = null,
    ): JsonResponse {
        return (new CommunicationGatewayQueryResource(
            $this->gateway->query(
                $request->actor(),
                $inbox,
                $type,
                $data ?? new CommunicationGatewayOperationData,
            ),
        ))->response();
    }
}
