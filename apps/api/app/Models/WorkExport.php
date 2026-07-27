<?php

namespace App\Models;

use App\Enums\Work\WorkExportStatus;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\WorkExportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Export CSV operacional — separado do Export ZIP/XML fiscal.
 * storage_path nunca é exposto.
 */
#[Fillable([
    'tenant_id',
    'requested_by_membership_id',
    'status',
    'filters_snapshot',
    'storage_path',
    'byte_size',
    'row_count',
    'error_message',
    'expires_at',
    'completed_at',
])]
#[Hidden(['storage_path'])]
class WorkExport extends Model
{
    /** @use HasFactory<WorkExportFactory> */
    use BelongsToTenant, HasFactory;

    protected $table = 'work_exports';

    protected function casts(): array
    {
        return [
            'status' => WorkExportStatus::class,
            'filters_snapshot' => 'array',
            'byte_size' => 'integer',
            'row_count' => 'integer',
            'expires_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    public function requestedByMembership(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'requested_by_membership_id');
    }

    protected static function newFactory(): WorkExportFactory
    {
        return WorkExportFactory::new();
    }
}
