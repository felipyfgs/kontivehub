<?php

namespace App\Enums;

enum PgdasdOperationAmountSource: string
{
    case TaxGuide = 'TAX_GUIDE';
    case GerarDas = 'GERAR_DAS';
    case ExtratoParse = 'EXTRATO_PARSE';
}
