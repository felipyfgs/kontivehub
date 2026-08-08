<?php

namespace App\Models;

use App\Enums\Communication\StickerAvailability;
use App\Enums\Communication\StickerSource;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable([
    'tenant_id', 'inbox_id', 'content_id', 'public_id', 'observation_id', 'source',
    'availability', 'unavailable_reason', 'device_favorite', 'app_favorite',
    'metadata_encrypted', 'device_favorite_observed_at', 'last_observed_at',
    'removed_at', 'expires_at',
])]
class CommunicationStickerObservation extends Model
{
    use BelongsToTenant;
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (self $observation): void {
            $observation->public_id ??= (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return [
            'source' => StickerSource::class,
            'availability' => StickerAvailability::class,
            'device_favorite' => 'boolean',
            'app_favorite' => 'boolean',
            'metadata_encrypted' => 'encrypted:array',
            'device_favorite_observed_at' => 'immutable_datetime',
            'last_observed_at' => 'immutable_datetime',
            'removed_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }

    public function resolveRouteBinding($value, $field = null): ?self
    {
        return $this->where($field ?? 'public_id', $value)->first();
    }

    /** @param Builder<self> $query */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->whereNull('removed_at');
    }

    public function inbox(): BelongsTo
    {
        return $this->belongsTo(CommunicationInbox::class, 'inbox_id');
    }

    public function content(): BelongsTo
    {
        return $this->belongsTo(CommunicationStickerContent::class, 'content_id');
    }
}
