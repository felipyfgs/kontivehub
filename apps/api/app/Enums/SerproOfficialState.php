<?php

namespace App\Enums;

/**
 * Estado oficial do serviço no catálogo SERPRO (não confundir com suporte da plataforma).
 */
enum SerproOfficialState: string
{
    case Production = 'PRODUCTION';
    case Prospection = 'PROSPECTION';
    case UnderConstruction = 'UNDER_CONSTRUCTION';
    case Canceled = 'CANCELED';
}
