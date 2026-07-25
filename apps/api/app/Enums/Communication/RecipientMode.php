<?php

namespace App\Enums\Communication;

enum RecipientMode: string
{
    case Primary = 'PRIMARY';
    case AllEligible = 'ALL_ELIGIBLE';
    case Selected = 'SELECTED';
}
