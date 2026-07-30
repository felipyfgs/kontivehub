<?php

namespace App\Models;

use App\Enums\Communication\ConversationBulkAction;
use App\Enums\Communication\ConversationBulkOperationStatus;
use App\Enums\TenantAccessMode;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'public_id',
    'access_mode',
    'idempotency_key',
    'payload_digest',
    'action',
    'params',
])]
class CommunicationConversationBulkOperation extends Model
{
    use BelongsToTenant;
    use HasFactory;

    protected function casts(): array
    {
        return [
            'action' => ConversationBulkAction::class,
            'status' => ConversationBulkOperationStatus::class,
            'access_mode' => TenantAccessMode::class,
            'params' => 'array',
            'item_count' => 'integer',
            'succeeded_count' => 'integer',
            'skipped_count' => 'integer',
            'failed_count' => 'integer',
            'queued_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(
            CommunicationConversationBulkOperationItem::class,
            'bulk_operation_id',
        );
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function requesterMembership(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'requested_by_membership_id');
    }
}
