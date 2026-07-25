<?php

namespace App\Exceptions;

use RuntimeException;

final class RecentPasswordRequiredException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Confirme sua senha novamente antes de liberar o módulo.');
    }
}
