<?php

namespace App\Models;

use App\Enums\Communication\RecipientMode;
use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'office_id',
    'module_key',
    'submodule_key',
    'inbox_id',
    'is_enabled',
    'send_day',
    'send_time',
    'timezone',
    'recipient_mode',
    'template_key',
    'template_version',
    'lock_version',
])]
class CommunicationAutomationPolicy extends Model
{
    use BelongsToOffice;

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'send_day' => 'integer',
            'recipient_mode' => RecipientMode::class,
            'lock_version' => 'integer',
        ];
    }

    public function inbox(): BelongsTo
    {
        return $this->belongsTo(CommunicationInbox::class, 'inbox_id');
    }
}
