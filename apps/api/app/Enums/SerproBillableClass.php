<?php

namespace App\Enums;

enum SerproBillableClass: string
{
    case Consulta = 'CONSULTA';
    case Emissao = 'EMISSAO';
    case Declaracao = 'DECLARACAO';
    case NaoFaturavel = 'NAO_FATURAVEL';
    case Desconhecida = 'DESCONHECIDA';
}
