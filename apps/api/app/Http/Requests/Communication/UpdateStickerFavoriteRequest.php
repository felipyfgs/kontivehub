<?php

namespace App\Http\Requests\Communication;

use App\Models\CommunicationStickerObservation;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class UpdateStickerFavoriteRequest extends TenantScopedRequest
{
    protected function prepareScopedValidation(): void
    {
        if ($this->has('favorite')) {
            $this->merge(['favorite' => $this->boolean('favorite')]);
        }
    }

    public function authorize(): bool
    {
        return $this->user() instanceof User
            && $this->route('sticker') instanceof CommunicationStickerObservation
            && Gate::forUser($this->user())->allows('update', $this->route('sticker'));
    }

    public function rules(): array
    {
        return ['favorite' => ['required', 'boolean']];
    }
}
