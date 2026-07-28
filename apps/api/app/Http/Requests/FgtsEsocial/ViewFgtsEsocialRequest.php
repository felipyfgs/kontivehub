<?php

namespace App\Http\Requests\FgtsEsocial;

abstract class ViewFgtsEsocialRequest extends FgtsEsocialRequest
{
    final public function authorize(): bool
    {
        return $this->canView();
    }
}
