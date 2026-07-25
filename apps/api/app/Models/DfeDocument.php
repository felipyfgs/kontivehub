<?php

namespace App\Models;

use App\Enums\AdnDocumentType;
use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'office_id', 'sha256', 'document_type', 'schema_version', 'access_key',
    'vault_object_id', 'byte_size', 'parse_status', 'parse_alert',
])]
#[Hidden(['vault_object_id'])]
class DfeDocument extends Model
{
    use BelongsToOffice;

    protected function casts(): array
    {
        return [
            'document_type' => AdnDocumentType::class,
            'byte_size' => 'integer',
        ];
    }

    public function interests(): HasMany
    {
        return $this->hasMany(DocumentInterest::class);
    }

    public function acquisitions(): HasMany
    {
        return $this->hasMany(DocumentAcquisition::class);
    }

    public function note(): HasOne
    {
        return $this->hasOne(NfseNote::class);
    }
}
