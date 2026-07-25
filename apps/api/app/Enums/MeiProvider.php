<?php

namespace App\Enums;

enum MeiProvider: string
{
    case ReceitaPortal = 'RECEITA_PORTAL';
    case Serpro = 'SERPRO';
    case Fixture = 'FIXTURE';
}
