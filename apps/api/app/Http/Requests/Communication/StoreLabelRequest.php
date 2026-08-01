<?php

namespace App\Http\Requests\Communication;

use App\DTO\Communication\LabelCreationData;
use App\Models\User;
use App\Services\Communication\Authorization\Access;

final class StoreLabelRequest extends TenantScopedRequest
{
    private const COLORS = [
        'neutral', 'red', 'orange', 'amber', 'yellow', 'lime', 'green', 'emerald',
        'teal', 'cyan', 'sky', 'blue', 'indigo', 'violet', 'purple', 'fuchsia',
        'pink', 'rose',
    ];

    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User
            && app(Access::class)->canManage($actor);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'color' => ['nullable', 'string', 'in:'.implode(',', self::COLORS)],
        ];
    }

    protected function prepareScopedValidation(): void
    {
        if ($this->has('name') && is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->string('name')->toString())]);
        }
    }

    public function labelData(): LabelCreationData
    {
        $validated = $this->validated();

        return new LabelCreationData(
            name: $validated['name'],
            color: $validated['color'] ?? 'neutral',
        );
    }
}
