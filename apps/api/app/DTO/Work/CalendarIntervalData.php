<?php

namespace App\DTO\Work;

final readonly class CalendarIntervalData
{
    public function __construct(
        public string $from,
        public string $to,
        public ?int $departmentId,
        public ?int $assigneeMembershipId,
        public ?int $clientId,
        public ?string $status,
        public ?string $risk,
    ) {}

    /**
     * @return array{
     *   from: string,
     *   to: string,
     *   department_id: int|null,
     *   assignee_membership_id: int|null,
     *   client_id: int|null,
     *   status: string|null,
     *   risk: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'from' => $this->from,
            'to' => $this->to,
            'department_id' => $this->departmentId,
            'assignee_membership_id' => $this->assigneeMembershipId,
            'client_id' => $this->clientId,
            'status' => $this->status,
            'risk' => $this->risk,
        ];
    }
}
