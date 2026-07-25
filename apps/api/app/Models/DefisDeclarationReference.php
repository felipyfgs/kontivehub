<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Referência opaca para idDefis; o valor real existe exclusivamente no cofre. */
#[Fillable(['office_id', 'client_id', 'vault_object_id', 'observed_at', 'source_run_id', 'source_provenance'])]
class DefisDeclarationReference extends Model
{
    use BelongsToOffice;

    protected function casts(): array
    {
        return ['observed_at' => 'immutable_datetime'];
    }
}
