<?php

namespace App\Http\Requests\Communication;

final class ViewFlowRequest extends FlowRequest
{
    public function authorize(): bool
    {
        return $this->canViewFlow();
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [];
    }
}
