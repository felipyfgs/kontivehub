<?php

namespace App\Models;

use App\Enums\ImportBatchItemStatus;
use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'office_id', 'document_import_batch_id', 'item_index', 'source_name', 'entry_name',
    'sha256', 'access_key', 'model', 'issuer_cnpj', 'establishment_id', 'dfe_document_id',
    'status', 'result_code', 'result_message', 'attempts', 'byte_size',
    'spool_vault_object_id', 'processed_at',
])]
#[Hidden(['spool_vault_object_id'])]
class DocumentImportBatchItem extends Model
{
    use BelongsToOffice;
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => ImportBatchItemStatus::class,
            'item_index' => 'integer',
            'attempts' => 'integer',
            'byte_size' => 'integer',
            'processed_at' => 'immutable_datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(DocumentImportBatch::class, 'document_import_batch_id');
    }

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class);
    }

    public function dfeDocument(): BelongsTo
    {
        return $this->belongsTo(DfeDocument::class, 'dfe_document_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'batch_id' => $this->batch?->public_id,
            'item_index' => $this->item_index,
            'source_name' => $this->source_name,
            'entry_name' => $this->entry_name,
            'sha256' => $this->sha256,
            'access_key' => $this->access_key,
            'model' => $this->model,
            'issuer_cnpj' => $this->issuer_cnpj,
            'establishment_id' => $this->establishment_id,
            'status' => $this->status->value,
            'result_code' => $this->result_code,
            'result_message' => $this->result_message,
            'attempts' => $this->attempts,
            'byte_size' => $this->byte_size,
            'processed_at' => $this->processed_at?->toIso8601String(),
        ];
    }
}
