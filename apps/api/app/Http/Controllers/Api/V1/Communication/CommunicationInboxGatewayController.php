<?php

namespace App\Http\Controllers\Api\V1\Communication;

use App\Enums\Communication\GatewayCommandType;
use App\Enums\Communication\GatewayQueryType;
use App\Enums\Communication\InboxStatus;
use App\Http\Controllers\Controller;
use App\Models\CommunicationIdentity;
use App\Models\CommunicationInbox;
use App\Models\CommunicationOutboxEntry;
use App\Models\User;
use App\Services\Communication\Gateway\CommunicationGatewayOperations;
use App\Services\Communication\Pairing\CommunicationPairingStateStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Controles administrativos do device ligado a uma inbox do Office atual. */
final class CommunicationInboxGatewayController extends Controller
{
    public function __construct(
        private readonly CommunicationGatewayOperations $operations,
        private readonly CommunicationPairingStateStore $pairing,
    ) {}

    public function sessionStatus(Request $request, int $inbox): JsonResponse
    {
        $model = $this->inbox($inbox);

        return response()->json(['data' => $this->operations->sessionStatus(
            $this->actor($request),
            $model,
        )]);
    }

    public function connect(Request $request, int $inbox): JsonResponse
    {
        return $this->command($request, $inbox, GatewayCommandType::ConnectSession);
    }

    public function disconnect(Request $request, int $inbox): JsonResponse
    {
        $model = $this->inbox($inbox);
        $entry = $this->operations->enqueue(
            $this->actor($request),
            $model,
            GatewayCommandType::DisconnectSession,
            [],
        );
        $model->forceFill([
            'status' => InboxStatus::Disconnected,
            'lock_version' => (int) $model->lock_version + 1,
        ])->save();
        $this->pairing->forget((int) $model->id);

        return $this->queued($entry);
    }

    public function reset(Request $request, int $inbox): JsonResponse
    {
        return $this->command($request, $inbox, GatewayCommandType::ResetSession);
    }

    public function passive(Request $request, int $inbox): JsonResponse
    {
        $data = $request->validate(['passive' => ['required', 'boolean']]);

        return $this->command($request, $inbox, GatewayCommandType::SetPassive, [
            'passive' => (bool) $data['passive'],
        ]);
    }

    public function pairPhone(Request $request, int $inbox): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'regex:/^\+[1-9][0-9]{7,14}$/'],
            'show_push_notification' => ['nullable', 'boolean'],
        ]);

        return $this->command($request, $inbox, GatewayCommandType::PairPhone, [
            'phone' => (string) $data['phone'],
            'show_push_notification' => (bool) ($data['show_push_notification'] ?? false),
        ]);
    }

    public function respondPasskey(Request $request, int $inbox): JsonResponse
    {
        $data = $request->validate([
            'id' => ['required', 'string', 'max:512'],
            'client_data_json' => ['required', 'string', 'max:16384'],
            'authenticator_data' => ['required', 'string', 'max:16384'],
            'signature' => ['required', 'string', 'max:16384'],
        ]);

        return $this->command($request, $inbox, GatewayCommandType::RespondPasskey, $data);
    }

    public function confirmPasskey(Request $request, int $inbox): JsonResponse
    {
        $data = $request->validate([
            'id' => ['required', 'string', 'max:512'],
            'confirm' => ['required', 'boolean'],
        ]);

        return $this->command($request, $inbox, GatewayCommandType::ConfirmPasskey, [
            'id' => (string) $data['id'],
            'confirm' => (bool) $data['confirm'],
        ]);
    }

    public function globalPresence(Request $request, int $inbox): JsonResponse
    {
        $data = $request->validate([
            'presence' => ['required', Rule::in(['AVAILABLE', 'UNAVAILABLE'])],
            'force_active_delivery_receipts' => ['nullable', 'boolean'],
        ]);
        $payload = ['presence' => (string) $data['presence']];
        if (array_key_exists('force_active_delivery_receipts', $data)) {
            $payload['force_active_delivery_receipts'] = (bool) $data['force_active_delivery_receipts'];
        }

        return $this->command($request, $inbox, GatewayCommandType::SetPresence, $payload);
    }

    public function defaultDisappearing(Request $request, int $inbox): JsonResponse
    {
        $data = $request->validate([
            'timer_seconds' => ['required', 'integer', Rule::in([0, 86400, 604800, 7776000])],
        ]);

        return $this->command($request, $inbox, GatewayCommandType::SetDefaultDisappearing, [
            'timer_seconds' => (int) $data['timer_seconds'],
        ]);
    }

    public function syncState(Request $request, int $inbox): JsonResponse
    {
        return $this->command($request, $inbox, GatewayCommandType::UpdateChatState, ['action' => 'SYNC']);
    }

    public function markStateClean(Request $request, int $inbox): JsonResponse
    {
        $data = $request->validate(['timestamp' => ['required', 'integer', 'min:1']]);

        return $this->command($request, $inbox, GatewayCommandType::UpdateChatState, [
            'action' => 'MARK_CLEAN',
            'timestamp' => (int) $data['timestamp'],
        ]);
    }

    public function updateBlocklist(Request $request, int $inbox): JsonResponse
    {
        $model = $this->inbox($inbox);
        $data = $request->validate([
            'identity_id' => ['required', 'integer', 'min:1'],
            'action' => ['required', Rule::in(['BLOCK', 'UNBLOCK'])],
        ]);
        $identity = $this->identity((int) $data['identity_id']);

        return $this->queued($this->operations->enqueue(
            $this->actor($request),
            $model,
            GatewayCommandType::UpdateBlocklist,
            ['to' => $this->address($identity), 'action' => (string) $data['action']],
        ));
    }

    public function updatePrivacy(Request $request, int $inbox): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', Rule::in(['last', 'profile', 'readreceipts', 'online'])],
            'value' => ['required', Rule::in(['all', 'contacts', 'contact_blacklist', 'none', 'match_last_seen'])],
        ]);

        return $this->command($request, $inbox, GatewayCommandType::UpdatePrivacy, [
            'name' => (string) $data['name'],
            'value' => (string) $data['value'],
        ]);
    }

    public function checkUsers(Request $request, int $inbox): JsonResponse
    {
        return $this->usersQuery($request, $inbox, GatewayQueryType::CheckUsers);
    }

    public function userInfo(Request $request, int $inbox): JsonResponse
    {
        return $this->usersQuery($request, $inbox, GatewayQueryType::UserInfo);
    }

    public function businessProfiles(Request $request, int $inbox): JsonResponse
    {
        return $this->usersQuery($request, $inbox, GatewayQueryType::BusinessProfile);
    }

    public function profilePicture(Request $request, int $inbox): JsonResponse
    {
        $model = $this->inbox($inbox);
        $data = $request->validate([
            'identity_id' => ['required', 'integer', 'min:1'],
            'preview' => ['nullable', 'boolean'],
        ]);
        $identity = $this->identity((int) $data['identity_id']);

        return $this->query($request, $model, GatewayQueryType::ProfilePicture, [
            'user' => $this->address($identity),
            'preview' => (bool) ($data['preview'] ?? true),
        ]);
    }

    public function contactQrLink(Request $request, int $inbox): JsonResponse
    {
        $data = $request->validate(['revoke' => ['nullable', 'boolean']]);

        return $this->query($request, $this->inbox($inbox), GatewayQueryType::ContactQrLink, [
            'revoke' => (bool) ($data['revoke'] ?? false),
        ]);
    }

    public function resolveContactQr(Request $request, int $inbox): JsonResponse
    {
        $data = $request->validate(['link' => ['required', 'string', 'max:2048']]);

        return $this->query($request, $this->inbox($inbox), GatewayQueryType::ResolveContactQr, [
            'link' => trim((string) $data['link']),
        ]);
    }

    public function resolveBusinessLink(Request $request, int $inbox): JsonResponse
    {
        $data = $request->validate(['link' => ['required', 'string', 'max:2048']]);

        return $this->query($request, $this->inbox($inbox), GatewayQueryType::ResolveBusinessLink, [
            'link' => trim((string) $data['link']),
        ]);
    }

    public function blocklist(Request $request, int $inbox): JsonResponse
    {
        return $this->query($request, $this->inbox($inbox), GatewayQueryType::Blocklist);
    }

    public function privacy(Request $request, int $inbox): JsonResponse
    {
        return $this->query($request, $this->inbox($inbox), GatewayQueryType::PrivacySettings);
    }

    /** @param array<string, mixed> $payload */
    private function command(
        Request $request,
        int $inbox,
        GatewayCommandType $type,
        array $payload = [],
    ): JsonResponse {
        return $this->queued($this->operations->enqueue(
            $this->actor($request),
            $this->inbox($inbox),
            $type,
            $payload,
        ));
    }

    private function usersQuery(
        Request $request,
        int $inbox,
        GatewayQueryType $type,
    ): JsonResponse {
        $data = $request->validate([
            'users' => ['required', 'array', 'min:1', 'max:100'],
            'users.*' => ['required', 'string', 'regex:/^\+[1-9][0-9]{7,14}$/', 'distinct'],
        ]);

        return $this->query($request, $this->inbox($inbox), $type, [
            'users' => array_values($data['users']),
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function query(
        Request $request,
        CommunicationInbox $inbox,
        GatewayQueryType $type,
        array $payload = [],
    ): JsonResponse {
        return response()->json(['data' => $this->operations->query(
            $this->actor($request),
            $inbox,
            $type,
            $payload,
        )]);
    }

    private function inbox(int $id): CommunicationInbox
    {
        return CommunicationInbox::query()->findOrFail($id);
    }

    private function identity(int $id): CommunicationIdentity
    {
        return CommunicationIdentity::query()->findOrFail($id);
    }

    private function address(CommunicationIdentity $identity): string
    {
        $address = trim((string) $identity->address_encrypted);
        abort_if($address === '', 422, 'Identidade sem endereço utilizável.');

        return $address;
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }

    private function queued(CommunicationOutboxEntry $entry): JsonResponse
    {
        return response()->json(['data' => [
            'command_id' => $entry->command_id,
            'type' => $entry->type->value,
            'status' => $entry->status->value,
        ]], 202);
    }
}
