<?php

namespace App\Models;

use App\Enums\Communication\ConversationListSort;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'status',
    'sort_by',
])]
class CommunicationConversationListPreference extends Model
{
    use BelongsToTenant;
    use HasFactory;

    protected function casts(): array
    {
        return [
            'sort_by' => ConversationListSort::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
