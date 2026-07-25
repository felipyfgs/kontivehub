<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'office_id',
    'client_id',
    'bootstrap_completed_at',
    'last_event_observed_date',
    'pending_event_date',
    'last_reconciled_event_date',
    'last_list_at',
    'last_full_reconciliation_at',
    'authorization_status',
    'last_error_code',
    'last_error_message',
])]
class MailboxClientSyncState extends Model
{
    use BelongsToOffice;

    protected function casts(): array
    {
        return [
            'bootstrap_completed_at' => 'immutable_datetime',
            'last_event_observed_date' => 'immutable_date',
            'pending_event_date' => 'immutable_date',
            'last_reconciled_event_date' => 'immutable_date',
            'last_list_at' => 'immutable_datetime',
            'last_full_reconciliation_at' => 'immutable_datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
