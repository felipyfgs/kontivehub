<?php

namespace App\Models;

use App\Enums\MailboxEventItemClassification;
use App\Enums\MailboxEventProcessingStatus;
use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'serpro_eventos_run_id',
    'office_id',
    'client_id',
    'ni_fingerprint',
    'classification',
    'event_date',
    'processing_status',
    'directed_run_id',
    'error_code',
    'error_message',
])]
class SerproEventosRunItem extends Model
{
    use BelongsToOffice;

    protected function casts(): array
    {
        return [
            'classification' => MailboxEventItemClassification::class,
            'event_date' => 'immutable_date',
            'processing_status' => MailboxEventProcessingStatus::class,
        ];
    }

    public function eventosRun(): BelongsTo
    {
        return $this->belongsTo(SerproEventosRun::class, 'serpro_eventos_run_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function directedRun(): BelongsTo
    {
        return $this->belongsTo(FiscalMonitoringRun::class, 'directed_run_id');
    }
}
