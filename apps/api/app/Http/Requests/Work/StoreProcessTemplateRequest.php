<?php

namespace App\Http\Requests\Work;

use App\DTO\Work\ProcessTemplateData;
use App\Models\User;
use App\Models\WorkProcessTemplate;
use App\Services\Work\ProcessTemplateWriter;

final class StoreProcessTemplateRequest extends TenantScopedRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User
            && $actor->can('create', WorkProcessTemplate::class);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return app(ProcessTemplateWriter::class)->rules();
    }

    public function payload(): ProcessTemplateData
    {
        return new ProcessTemplateData($this->validated());
    }
}
