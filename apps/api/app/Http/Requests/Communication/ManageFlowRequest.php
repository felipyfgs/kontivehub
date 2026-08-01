<?php

namespace App\Http\Requests\Communication;

final class ManageFlowRequest extends FlowRequest
{
    public function authorize(): bool
    {
        return $this->canManageFlow();
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [];
    }
}
