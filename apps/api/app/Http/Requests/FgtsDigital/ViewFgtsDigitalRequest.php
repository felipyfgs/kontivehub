<?php

namespace App\Http\Requests\FgtsDigital;

class ViewFgtsDigitalRequest extends FgtsDigitalRequest
{
    public function authorize(): bool
    {
        return $this->canView();
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [];
    }
}
