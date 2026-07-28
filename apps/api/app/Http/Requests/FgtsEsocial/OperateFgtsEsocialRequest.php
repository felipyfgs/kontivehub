<?php

namespace App\Http\Requests\FgtsEsocial;

abstract class OperateFgtsEsocialRequest extends FgtsEsocialRequest
{
    final public function authorize(): bool
    {
        return $this->canOperate();
    }
}
