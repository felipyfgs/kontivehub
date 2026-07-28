<?php

namespace App\DTO\Communication;

final readonly class CommunicationInboxMembersData
{
    /** @param list<int> $membershipIds */
    public function __construct(
        public array $membershipIds,
    ) {}
}
