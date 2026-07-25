<?php

namespace App\Exceptions\Adn;

class AdnInvalidResponseException extends AdnPermanentException
{
    public function __construct()
    {
        parent::__construct('Resposta ADN inválida; cursor bloqueado para evitar salto de NSU.');
    }
}
