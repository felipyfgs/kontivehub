<?php

namespace App\Models;

use App\Enums\Work\OperationalExportStatus;
use App\Models\Concerns\BelongsToOffice;
use Database\Factories\OperationalExportFactory;
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
    'office_id',
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
class OperationalExport extends Model
{
    /** @use HasFactory<OperationalExportFactory> */
    use BelongsToOffice, HasFactory;

    protected $table = 'operational_exports';

    protected function casts(): array
    {
        return [
            'status' => OperationalExportStatus::class,
            'filters_snapshot' => 'array',
            'byte_size' => 'integer',
            'row_count' => 'integer',
            'expires_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    public function requestedByMembership(): BelongsTo
    {
        return $this->belongsTo(OfficeMembership::class, 'requested_by_membership_id');
    }

    protected static function newFactory(): OperationalExportFactory
    {
        return OperationalExportFactory::new();
    }
}
