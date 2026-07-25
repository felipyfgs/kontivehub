<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'office_id',
    'client_id',
    'field_key',
    'label',
    'type',
    'is_active',
    'value_text',
    'vault_object_id',
])]
#[Hidden(['field_key', 'vault_object_id'])]
class ClientCustomField extends Model
{
    use BelongsToOffice;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return array{id:int,label:string,type:string,is_active:bool,value:string|null,has_value:bool}
     */
    public function toPublicArray(): array
    {
        $isSecret = $this->type === 'SECRET';

        return [
            'id' => $this->id,
            'label' => $this->label,
            'type' => $this->type,
            'is_active' => (bool) $this->is_active,
            'value' => $isSecret ? null : $this->value_text,
            'has_value' => $isSecret ? $this->vault_object_id !== null : filled($this->value_text),
        ];
    }
}
