<?php

namespace App\Enums\Communication;

/**
 * Stable reasons advertised when an outbound family or variant is unavailable.
 *
 * These values are a public contract: clients may translate them, but must not
 * infer support from descriptor availability or a rendered control.
 */
enum OutboundCapabilityUnavailableReason: string
{
    case GatewayUnavailable = 'GATEWAY_UNAVAILABLE';
    case TenantDisabled = 'TENANT_DISABLED';
    case PermissionDenied = 'PERMISSION_DENIED';
    case InboxDisabled = 'INBOX_DISABLED';
    case InboxDisconnected = 'INBOX_DISCONNECTED';
    case RolloutDisabled = 'ROLLOUT_DISABLED';
    case GifProviderDisabled = 'GIF_PROVIDER_DISABLED';
    case MessageBatchUnimplemented = 'MESSAGE_BATCH_UNIMPLEMENTED';
    case ContactsArrayBuilderUnimplemented = 'CONTACTS_ARRAY_BUILDER_UNIMPLEMENTED';
    case NativeAlbumInteroperabilityUnverified = 'NATIVE_ALBUM_INTEROPERABILITY_UNVERIFIED';
    case GifPlaybackBuilderUnimplemented = 'GIF_PLAYBACK_BUILDER_UNIMPLEMENTED';
    case PtvBuilderUnimplemented = 'PTV_BUILDER_UNIMPLEMENTED';
    case EventBuilderUnimplemented = 'EVENT_BUILDER_UNIMPLEMENTED';
    case ViewOnceBuilderUnimplemented = 'VIEW_ONCE_BUILDER_UNIMPLEMENTED';
    case MessageKindUnsupported = 'MESSAGE_KIND_UNSUPPORTED';
}
