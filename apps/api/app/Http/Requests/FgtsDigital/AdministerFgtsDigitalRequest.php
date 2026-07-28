<?php

namespace App\Http\Requests\FgtsDigital;

class AdministerFgtsDigitalRequest extends FgtsDigitalRequest
{
    public function authorize(): bool
    {
        return $this->canAdminister();
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [];
    }
}
