<?php

namespace App\Http\Resources\Import;

use App\Enums\ImportBatchItemStatus;
use App\Models\DocumentImportBatchItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DocumentImportBatchItem */
final class DocumentImportBatchItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $batch = $this->relationLoaded('batch') ? $this->batch : null;

        return [
            'id' => $this->id,
            'batch_id' => $batch?->public_id,
            'item_index' => $this->item_index,
            'source_name' => $this->source_name,
            'entry_name' => $this->entry_name,
            'sha256' => $this->sha256,
            'access_key' => $this->access_key,
            'model' => $this->model,
            'issuer_cnpj' => $this->issuer_cnpj,
            'establishment_id' => $this->establishment_id,
            'status' => $this->status instanceof ImportBatchItemStatus
                ? $this->status->value
                : (string) $this->status,
            'result_code' => $this->result_code,
            'result_message' => $this->result_message,
            'attempts' => $this->attempts,
            'byte_size' => $this->byte_size,
            'processed_at' => $this->processed_at?->toIso8601String(),
        ];
    }
}
