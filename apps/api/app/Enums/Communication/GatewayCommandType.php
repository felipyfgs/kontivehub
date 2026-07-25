<?php

namespace App\Enums\Communication;

enum GatewayCommandType: string
{
    case ProvisionSession = 'SESSION_PROVISION';
    case PairSession = 'SESSION_PAIR';
    case PairPhone = 'SESSION_PAIR_PHONE';
    case RespondPasskey = 'SESSION_PASSKEY_RESPOND';
    case ConfirmPasskey = 'SESSION_PASSKEY_CONFIRM';
    case ConnectSession = 'SESSION_CONNECT';
    case DisconnectSession = 'SESSION_DISCONNECT';
    case ResetSession = 'SESSION_RESET';
    case SetPassive = 'SESSION_SET_PASSIVE';
    case SendMessage = 'MESSAGE_SEND';
    case EditMessage = 'MESSAGE_EDIT';
    case RevokeMessage = 'MESSAGE_REVOKE';
    case ReactMessage = 'MESSAGE_REACT';
    case VotePoll = 'POLL_VOTE';
    case MarkMessage = 'MESSAGE_MARK';
    case RequestUnavailableMessage = 'MESSAGE_REQUEST_UNAVAILABLE';
    case RequestMediaRetry = 'MEDIA_RETRY_REQUEST';
    case SetPresence = 'PRESENCE_SET';
    case SubscribePresence = 'PRESENCE_SUBSCRIBE';
    case SetChatPresence = 'CHAT_PRESENCE_SET';
    case SetChatDisappearing = 'CHAT_DISAPPEARING_SET';
    case UpdateChatState = 'CHAT_STATE_UPDATE';
    case UpdateBlocklist = 'BLOCKLIST_UPDATE';
    case UpdatePrivacy = 'PRIVACY_UPDATE';
    case SetDefaultDisappearing = 'DEFAULT_DISAPPEARING_SET';
    case RequestHistorySync = 'HISTORY_SYNC_REQUEST';
    case LogoutSession = 'SESSION_LOGOUT';
}
