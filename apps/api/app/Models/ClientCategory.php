<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable([
    'office_id',
    'name',
    'name_key',
    'color',
    'is_active',
    'created_by',
])]
class ClientCategory extends Model
{
    use BelongsToOffice;

    /** Paleta curada para tags (legado semântico + matizes extras). */
    public const COLORS = [
        'primary',
        'secondary',
        'success',
        'info',
        'warning',
        'error',
        'neutral',
        'rose',
        'pink',
        'fuchsia',
        'purple',
        'indigo',
        'sky',
        'cyan',
        'teal',
        'emerald',
        'lime',
        'yellow',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public static function normalizeNameKey(string $name): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', trim($name)) ?? trim($name);

        return mb_strtolower($collapsed);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function clients(): BelongsToMany
    {
        return $this->belongsToMany(Client::class, 'client_category_assignments')
            ->withPivot(['office_id', 'assigned_by'])
            ->withTimestamps();
    }
}
