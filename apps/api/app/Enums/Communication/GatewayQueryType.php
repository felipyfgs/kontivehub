<?php

namespace App\Enums\Communication;

enum GatewayQueryType: string
{
    case CheckUsers = 'USER_CHECK';
    case UserInfo = 'USER_INFO';
    case BusinessProfile = 'BUSINESS_PROFILE';
    case ProfilePicture = 'PROFILE_PICTURE';
    case ContactQrLink = 'CONTACT_QR_LINK';
    case ResolveContactQr = 'CONTACT_QR_RESOLVE';
    case ResolveBusinessLink = 'BUSINESS_LINK_RESOLVE';
    case Blocklist = 'BLOCKLIST';
    case PrivacySettings = 'PRIVACY_SETTINGS';
}
