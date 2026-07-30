<?php

namespace App\Models;

use App\Enums\Communication\ProfilePictureState;
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
    'profile_picture_state', 'profile_picture_object_id', 'profile_picture_mime_type',
    'profile_picture_size_bytes', 'profile_picture_sha256', 'profile_picture_storage_context',
    'profile_picture_version', 'profile_picture_fetched_at', 'profile_picture_retry_at', 'profile_picture_error_code',
])]
class CommunicationInboxIdentityProfile extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'field_versions' => 'array',
            'cleared_fields' => 'array',
            'profile_picture_state' => ProfilePictureState::class,
            'profile_picture_size_bytes' => 'integer',
            'profile_picture_version' => 'integer',
            'profile_picture_storage_context' => 'array',
            'profile_picture_fetched_at' => 'immutable_datetime',
            'profile_picture_retry_at' => 'immutable_datetime',
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
