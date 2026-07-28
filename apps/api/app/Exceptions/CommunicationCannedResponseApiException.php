<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;

final class CommunicationCannedResponseApiException extends ApiDomainException implements ShouldntReport
{
    public static function shortcutConflict(): self
    {
        return new self(
            'shortcut_conflict',
            'Já existe uma resposta rápida com este atalho neste escritório.',
            409,
        );
    }

    public static function versionConflict(): self
    {
        return new self(
            'version_conflict',
            'Resposta rápida foi alterada por outro usuário.',
            409,
        );
    }

    private function __construct(string $code, string $message, int $status)
    {
        parent::__construct($code, $message, $status);
    }
}
