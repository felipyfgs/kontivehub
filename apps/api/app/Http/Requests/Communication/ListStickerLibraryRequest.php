<?php

namespace App\Http\Requests\Communication;

use App\Enums\Communication\StickerSource;
use App\Models\CommunicationInbox;
use App\Models\User;
use App\Services\Communication\Authorization\Access;
use Illuminate\Validation\Rule;

final class ListStickerLibraryRequest extends TenantScopedRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User
            && $this->route('inbox') instanceof CommunicationInbox
            && app(Access::class)->canView($this->user(), $this->route('inbox'));
    }

    public function rules(): array
    {
        return [
            'favorite' => ['nullable', Rule::in(['app', 'device', 'any'])],
            'source' => ['nullable', Rule::enum(StickerSource::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? 30);
    }
}
