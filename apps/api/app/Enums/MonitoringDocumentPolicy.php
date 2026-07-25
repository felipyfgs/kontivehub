<?php

namespace App\Enums;

/**
 * Política de publicação de documento/evidência na superfície.
 */
enum MonitoringDocumentPolicy: string
{
    case Never = 'NEVER';
    case WhenArtifact = 'WHEN_ARTIFACT';
    case AsyncWhenReady = 'ASYNC_WHEN_READY';
}
