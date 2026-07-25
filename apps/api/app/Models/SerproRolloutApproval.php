<?php

namespace App\Models;

use App\Enums\SerproApprovalPolicy;
use App\Enums\SerproEnvironment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'subject_type',
    'subject_id',
    'action',
    'approval_policy',
    'environment',
    'office_id',
    'status',
    'reason',
    'confirmation_phrase',
    'requested_by_user_id',
    'first_approver_user_id',
    'second_approver_user_id',
    'first_approved_at',
    'second_approved_at',
    'executed_at',
    'expires_at',
    'change_window_start',
    'change_window_end',
    'context',
])]
class SerproRolloutApproval extends Model
{
    protected function casts(): array
    {
        return [
            'environment' => SerproEnvironment::class,
            'approval_policy' => SerproApprovalPolicy::class,
            'first_approved_at' => 'immutable_datetime',
            'second_approved_at' => 'immutable_datetime',
            'executed_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'change_window_start' => 'immutable_datetime',
            'change_window_end' => 'immutable_datetime',
            'context' => 'array',
        ];
    }

    public function policy(): SerproApprovalPolicy
    {
        if ($this->approval_policy instanceof SerproApprovalPolicy) {
            return $this->approval_policy;
        }

        return SerproApprovalPolicy::tryFrom((string) $this->approval_policy)
            ?? SerproApprovalPolicy::DualRole;
    }

    /**
     * Aprovação completa segundo a política da ação (allowlist fechada no serviço).
     */
    public function isFullyApproved(): bool
    {
        if (! in_array($this->status, ['APPROVED', 'EXECUTED'], true)) {
            return false;
        }

        if ($this->first_approver_user_id === null) {
            return false;
        }

        return match ($this->policy()) {
            SerproApprovalPolicy::OwnerConfirmation => true,
            SerproApprovalPolicy::DualRole => $this->second_approver_user_id !== null
                && (int) $this->first_approver_user_id !== (int) $this->second_approver_user_id,
        };
    }

    public function isExpired(): bool
    {
        if ($this->status === 'EXPIRED') {
            return true;
        }

        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isChangeWindowActive(?\DateTimeInterface $at = null): bool
    {
        if ($this->change_window_start === null || $this->change_window_end === null) {
            return false;
        }

        $now = $at !== null
            ? CarbonImmutable::instance($at)
            : CarbonImmutable::now();

        return $now->betweenIncluded($this->change_window_start, $this->change_window_end);
    }
}
