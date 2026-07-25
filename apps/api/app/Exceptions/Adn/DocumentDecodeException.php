<?php

namespace App\Exceptions\Adn;

use RuntimeException;

class DocumentDecodeException extends RuntimeException
{
    // A mensagem é sempre definida localmente e nunca inclui o payload fiscal.
}
