<?php

namespace App\Services\Communication\StickerLibrary;

use App\Models\CommunicationStickerContent;
use Illuminate\Validation\ValidationException;

final class StickerQuota
{
    public function assertCanAdd(int $tenantId, int $bytes): void
    {
        $usage = CommunicationStickerContent::query()->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->selectRaw('COUNT(*) AS item_count, COALESCE(SUM(size_bytes), 0) AS byte_count')
            ->first();
        $maxItems = max(1, (int) config('communication.sticker_library.max_items_per_tenant', 500));
        $maxBytes = max(1, (int) config('communication.sticker_library.max_bytes_per_tenant', 104_857_600));
        if ((int) ($usage?->item_count ?? 0) >= $maxItems
            || (int) ($usage?->byte_count ?? 0) + $bytes > $maxBytes) {
            throw ValidationException::withMessages([
                'file' => 'A quota privada de figurinhas do tenant foi atingida.',
            ]);
        }
    }
}
