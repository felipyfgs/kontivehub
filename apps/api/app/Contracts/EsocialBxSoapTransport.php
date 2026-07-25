<?php

namespace App\Contracts;

use App\DTO\Esocial\EsocialBxHttpResponse;

interface EsocialBxSoapTransport
{
    public function post(
        string $endpoint,
        string $soapAction,
        string $envelope,
        string $pfxBinary,
        string $password,
    ): EsocialBxHttpResponse;
}
