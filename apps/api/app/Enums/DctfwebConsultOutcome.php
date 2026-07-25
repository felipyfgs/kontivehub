<?php

namespace App\Enums;

/** Resultado normalizado de uma observação de consulta DCTFWeb (imutável). */
enum DctfwebConsultOutcome: string
{
    case Found = 'FOUND';
    case NotFound = 'NOT_FOUND';
    case Failed = 'FAILED';
    case InvalidDocument = 'INVALID_DOCUMENT';
    case Incomplete = 'INCOMPLETE';
}
