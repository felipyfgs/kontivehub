<?php

namespace App\Enums;

enum RegistrationSource: string
{
    case Manual = 'MANUAL';
    case CnpjWs = 'CNPJ_WS';
    case SerproConsulta = 'SERPRO_CONSULTA';
}
