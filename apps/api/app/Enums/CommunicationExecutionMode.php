<?php

namespace App\Enums;

enum CommunicationExecutionMode: string
{
    case TemplateOnly = 'TEMPLATE_ONLY';
    case WhatsAppNative = 'WHATSAPP_NATIVE';
}
