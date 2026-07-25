<?php

namespace App\Services\FgtsDigital\Clients;

use App\Contracts\FgtsDigitalPortalClient;
use App\DTO\FgtsDigital\FgtsDigitalPortalRequest;
use App\DTO\FgtsDigital\FgtsDigitalPortalResult;
use App\Services\FgtsDigital\Exceptions\FgtsDigitalException;

final class FixtureFgtsDigitalPortalClient implements FgtsDigitalPortalClient
{
    public function execute(FgtsDigitalPortalRequest $request): FgtsDigitalPortalResult
    {
        $name = $request->fixture ?: strtolower($request->operation->value);
        if (! preg_match('/^[a-z0-9_-]+$/', $name)) {
            throw new FgtsDigitalException('Fixture FGTS inválida.', 'FGTS_DIGITAL_FIXTURE_INVALID', 422);
        }
        $path = rtrim((string) config('fgts_digital.runtime.fixtures'), '/').'/'.$name.'.json';
        if (! is_file($path) || ! is_readable($path)) {
            throw new FgtsDigitalException('Fixture FGTS não encontrada.', 'FGTS_DIGITAL_FIXTURE_NOT_FOUND', 503);
        }
        $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return FgtsDigitalPortalResult::fromTransportArray($payload);
    }
}
