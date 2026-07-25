<?php

namespace App\Enums;

enum OutboundDeadlineSource: string
{
    case Authorization = 'AUTHORIZATION';
    case AccessKeyYm = 'ACCESS_KEY_YM';
    case Manual = 'MANUAL';

    public function isProvisional(): bool
    {
        return $this === self::AccessKeyYm;
    }
}
