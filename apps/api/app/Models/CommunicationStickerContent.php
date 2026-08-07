<?php

namespace App\Models;

use App\Enums\Communication\StickerSource;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable([
    'tenant_id', 'public_id', 'sha256', 'object_id_encrypted', 'storage_context_encrypted', 'mime_type', 'size_bytes',
    'width', 'height', 'animated', 'provenance', 'retention_protected',
    'last_referenced_at', 'expires_at',
])]
class CommunicationStickerContent extends Model
{
    use BelongsToTenant;
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (self $content): void {
            $content->public_id ??= (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return [
            'object_id_encrypted' => 'encrypted',
            'storage_context_encrypted' => 'encrypted:array',
            'size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'animated' => 'boolean',
            'provenance' => StickerSource::class,
            'retention_protected' => 'boolean',
            'last_referenced_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function observations(): HasMany
    {
        return $this->hasMany(CommunicationStickerObservation::class, 'content_id');
    }
}
