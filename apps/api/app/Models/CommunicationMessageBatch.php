<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'tenant_id',
    'inbox_id',
    'conversation_id',
    'client_batch_id',
    'request_digest',
    'status',
    'item_count',
])]
final class CommunicationMessageBatch extends Model
{
    use BelongsToTenant;
    use HasFactory;

    protected function casts(): array
    {
        return ['item_count' => 'integer'];
    }

    public function inbox(): BelongsTo
    {
        return $this->belongsTo(CommunicationInbox::class, 'inbox_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(CommunicationConversation::class, 'conversation_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(CommunicationMessage::class, 'message_batch_id')
            ->orderBy('batch_position');
    }

    public function outboxEntry(): HasOne
    {
        return $this->hasOne(CommunicationOutboxEntry::class, 'message_batch_id');
    }

    public function gatewayBatchId(): string
    {
        if (! $this->exists || (int) $this->id < 1) {
            throw new \LogicException('O lote precisa estar persistido para gerar correlação.');
        }

        return 'batch-'.substr(hash('sha256', implode('|', [
            (int) $this->tenant_id,
            (int) $this->inbox_id,
            (int) $this->conversation_id,
            (int) $this->id,
        ])), 0, 48);
    }
}
