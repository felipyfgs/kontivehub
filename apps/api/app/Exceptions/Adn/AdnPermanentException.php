<?php

namespace App\Exceptions\Adn;

class AdnPermanentException extends AdnException
{
    // Exige intervenção; o cursor não deve ser retentado automaticamente.
}
