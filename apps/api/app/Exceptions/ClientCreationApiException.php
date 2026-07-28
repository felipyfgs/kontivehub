<?php

namespace App\Exceptions;

use App\Services\Clients\DuplicateEstablishmentException;
use App\Services\Usage\ClientQuotaException;
use App\Support\LogSanitizer;
use Illuminate\Contracts\Debug\ShouldntReport;
use InvalidArgumentException;

final class ClientCreationApiException extends ApiDomainException implements ShouldntReport
{
    public static function duplicate(DuplicateEstablishmentException $error): self
    {
        $data = [
            'errors' => ['cnpj' => ['CNPJ já cadastrado neste escritório.']],
        ];

        if ($error->existingClient !== null) {
            $data['data'] = [
                'existing_client_id' => $error->existingClient->id,
                'existing_client' => [
                    'id' => $error->existingClient->id,
                    'legal_name' => $error->existingClient->legal_name,
                    'root_cnpj' => $error->existingClient->root_cnpj,
                ],
            ];
        }

        return new self(
            'client_cnpj_conflict',
            $error->getMessage(),
            409,
            $data,
        );
    }

    public static function duplicateConstraint(): self
    {
        return new self(
            'client_cnpj_conflict',
            'CNPJ já cadastrado neste escritório.',
            409,
            ['errors' => ['cnpj' => ['CNPJ já cadastrado neste escritório.']]],
        );
    }

    public static function invalidCnpj(InvalidArgumentException $error): self
    {
        $message = LogSanitizer::scrubString($error->getMessage());

        return new self(
            'client_cnpj_invalid',
            $message,
            422,
            ['errors' => ['cnpj' => [$message]]],
        );
    }

    public static function quotaExceeded(ClientQuotaException $error): self
    {
        return new self(
            'MAX_CLIENTS_REACHED',
            $error->getMessage(),
            422,
            ['errors' => ['max_clients' => [$error->getMessage()]]],
        );
    }

    /** @param array<string, mixed> $responseData */
    private function __construct(
        string $stableCode,
        string $safeMessage,
        int $httpStatus,
        array $responseData,
    ) {
        parent::__construct($stableCode, $safeMessage, $httpStatus, $responseData);
    }
}
