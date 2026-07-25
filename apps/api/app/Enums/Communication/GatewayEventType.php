<?php

namespace App\Enums\Communication;

enum GatewayEventType: string
{
    case MessageReceived = 'MESSAGE_RECEIVED';
    case MessageStatusChanged = 'MESSAGE_STATUS_CHANGED';
    case MessageActionReceived = 'MESSAGE_ACTION_RECEIVED';
    case SessionStatusChanged = 'SESSION_STATUS_CHANGED';
    case PairingUpdated = 'PAIRING_UPDATED';
    case MediaReady = 'MEDIA_READY';
    case ChatPresenceChanged = 'CHAT_PRESENCE_CHANGED';
    case ContactPresenceChanged = 'CONTACT_PRESENCE_CHANGED';
    case ContactProfileChanged = 'CONTACT_PROFILE_CHANGED';
    case ContactIdentityChanged = 'CONTACT_IDENTITY_CHANGED';
    case PrivacySettingsChanged = 'PRIVACY_SETTINGS_CHANGED';
    case BlocklistChanged = 'BLOCKLIST_CHANGED';
    case ChatStateChanged = 'CHAT_STATE_CHANGED';
    case HistorySynced = 'HISTORY_SYNCED';
    case SyncStatusChanged = 'SYNC_STATUS_CHANGED';
    case MediaRetryUpdated = 'MEDIA_RETRY_UPDATED';
    case GatewayAlert = 'GATEWAY_ALERT';
}
