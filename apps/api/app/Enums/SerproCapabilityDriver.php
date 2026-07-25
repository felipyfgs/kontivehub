<?php

namespace App\Enums;

/**
 * Driver de transporte por capacidade (sem fallback entre valores).
 */
enum SerproCapabilityDriver: string
{
    case Disabled = 'disabled';
    case Fixture = 'fixture';
    case Real = 'real';
}
