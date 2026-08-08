<?php

namespace App\Http\Requests\Communication;

use App\Models\CommunicationStickerObservation;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class RemoveStickerRequest extends TenantScopedRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User
            && $this->route('sticker') instanceof CommunicationStickerObservation
            && Gate::forUser($this->user())->allows('delete', $this->route('sticker'));
    }

    public function rules(): array
    {
        return [];
    }
}
