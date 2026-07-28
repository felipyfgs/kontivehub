<?php

namespace App\Exceptions;

use App\Support\LogSanitizer;
use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

final class ClientCredentialApiException extends ApiDomainException implements ShouldntReport
{
    public static function activationFailed(RuntimeException $error): self
    {
        $message = trim(LogSanitizer::scrubString($error->getMessage()));

        return new self(
            $message !== '' ? $message : 'Falha ao ativar certificado.',
        );
    }

    public static function unexpectedFailure(): self
    {
        return new self('Falha ao ativar certificado.');
    }

    private function __construct(string $safeMessage)
    {
        parent::__construct(
            'client_credential_activation_failed',
            $safeMessage,
            422,
        );
    }
}
