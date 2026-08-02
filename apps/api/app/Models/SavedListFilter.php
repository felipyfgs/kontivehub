<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Preset nomeado de filtros de lista (personal ou compartilhado no Tenant).
 *
 * tenant_id só via CurrentTenant / servidor — nunca autoridade do client.
 */
#[Fillable([
    'tenant_id',
    'user_id',
    'surface',
    'name',
    'visibility',
    'schema_version',
    'payload',
])]
class SavedListFilter extends Model
{
    use BelongsToTenant;

    public const VISIBILITY_PERSONAL = 'personal';

    public const VISIBILITY_TENANT = 'tenant';

    public const SCHEMA_VERSION = 1;

    public const SURFACE_COMMUNICATION_CONVERSATIONS = 'communication.conversations';

    /** @var list<string> */
    public const SURFACES = [
        'monitoring.simples_mei',
        'monitoring.dctfweb',
        'monitoring.installments',
        'monitoring.sitfis',
        'monitoring.declarations',
        'monitoring.fgts',
        'monitoring.guides',
        'monitoring.registrations',
        'monitoring.tax_processes',
        'monitoring.mailbox',
        'clients.index',
        'docs.catalog',
        'work.queue',
        'work.processes',
        'closing.list',
        self::SURFACE_COMMUNICATION_CONVERSATIONS,
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'schema_version' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isPersonal(): bool
    {
        return $this->visibility === self::VISIBILITY_PERSONAL;
    }

    public function isTenantShared(): bool
    {
        return $this->visibility === self::VISIBILITY_TENANT;
    }
}
