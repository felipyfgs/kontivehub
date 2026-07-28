<?php

namespace App\Http\Requests\FgtsDigital;

class OperateFgtsDigitalRequest extends FgtsDigitalRequest
{
    public function authorize(): bool
    {
        return $this->canOperate();
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [];
    }
}
