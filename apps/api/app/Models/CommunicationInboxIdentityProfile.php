<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id',
    'inbox_id',
    'identity_id',
    'address_book_first_name',
    'address_book_full_name',
    'verified_name',
    'business_name',
    'push_name',
    'picture_id',
    'about',
    'field_versions',
    'cleared_fields',
])]
class CommunicationInboxIdentityProfile extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'field_versions' => 'array',
            'cleared_fields' => 'array',
        ];
    }

    public function inbox(): BelongsTo
    {
        return $this->belongsTo(CommunicationInbox::class, 'inbox_id');
    }

    public function identity(): BelongsTo
    {
        return $this->belongsTo(CommunicationIdentity::class, 'identity_id');
    }
}
