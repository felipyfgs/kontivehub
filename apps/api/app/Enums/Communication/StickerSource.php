<?php

namespace App\Enums\Communication;

enum StickerSource: string
{
    case DeviceRecent = 'DEVICE_RECENT';
    case DeviceFavorite = 'DEVICE_FAVORITE';
    case DeviceMessage = 'DEVICE_MESSAGE';
    case LocalImport = 'LOCAL_IMPORT';
}
