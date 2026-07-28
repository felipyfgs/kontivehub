<?php

namespace App\DTO\Work;

final readonly class WorkCalendarDayData
{
    public function __construct(
        public string $date,
        public int $perPage,
        public int $page,
        public ?int $departmentId,
        public ?int $assigneeMembershipId,
        public ?int $clientId,
        public ?string $status,
        public ?string $risk,
    ) {}

    /**
     * @return array{
     *   date: string,
     *   per_page: int,
     *   page: int,
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
            'date' => $this->date,
            'per_page' => $this->perPage,
            'page' => $this->page,
            'department_id' => $this->departmentId,
            'assignee_membership_id' => $this->assigneeMembershipId,
            'client_id' => $this->clientId,
            'status' => $this->status,
            'risk' => $this->risk,
        ];
    }
}
