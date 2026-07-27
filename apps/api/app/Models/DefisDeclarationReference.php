<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Referência opaca para idDefis; o valor real existe exclusivamente no cofre. */
#[Fillable(['tenant_id', 'client_id', 'vault_object_id', 'observed_at', 'source_run_id', 'source_provenance'])]
class DefisDeclarationReference extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return ['observed_at' => 'immutable_datetime'];
    }
}
