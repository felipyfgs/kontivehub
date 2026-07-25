<?php

namespace App\Enums\Communication;

enum FlowStatus: string
{
    case Paused = 'paused';
    case Active = 'active';
}
