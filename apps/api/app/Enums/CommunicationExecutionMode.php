<?php

namespace App\Enums;

enum CommunicationExecutionMode: string
{
    case TemplateOnly = 'TEMPLATE_ONLY';
    case WhatsappNative = 'WHATSAPP_NATIVE';
}
