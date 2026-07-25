<?php

namespace App\Enums;

enum OfficeAutXmlEnrollmentStatus: string
{
    case Pending = 'PENDING';
    case Confirmed = 'CONFIRMED';
    case Inactive = 'INACTIVE';

    public function isEnrolled(): bool
    {
        return $this === self::Pending || $this === self::Confirmed;
    }
}
