<?php

namespace App\Exceptions\Adn;

class AdnRetryableException extends AdnException
{
    // Falha temporária; preserva o NSU e aplica backoff.
}
