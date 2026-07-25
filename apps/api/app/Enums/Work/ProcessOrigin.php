<?php

namespace App\Enums\Work;

/**
 * Origem de criação de um processo operacional.
 * TEMPLATE participa da unicidade por modelo+cliente+competência; MANUAL não.
 */
enum ProcessOrigin: string
{
    case Template = 'TEMPLATE';
    case Manual = 'MANUAL';
}
