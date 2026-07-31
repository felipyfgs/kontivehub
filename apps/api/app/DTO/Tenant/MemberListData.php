<?php

namespace App\DTO\Tenant;

final readonly class MemberListData
{
    /** @param list<array<string, mixed>> $members */
    public function __construct(
        public array $members,
        public int $occupiedSeats,
        public ?int $maxUsers,
    ) {}
}
