<?php

namespace App\Actions\Clients;

use App\DTO\Clients\ClientCreationData;
use App\DTO\Clients\ClientCreationResult;
use App\Exceptions\ClientCreationApiException;
use App\Services\Clients\CreateClientWithEstablishment;
use App\Services\Clients\DuplicateEstablishmentException;
use App\Services\Usage\ClientQuotaException;
use App\Support\CurrentTenant;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;

final readonly class CreateClientAction
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private CreateClientWithEstablishment $creator,
    ) {}

    public function __invoke(ClientCreationData $data): ClientCreationResult
    {
        try {
            $result = $this->creator->handle(
                $this->currentTenant->tenant()->id,
                $data->attributes,
            );
        } catch (DuplicateEstablishmentException $error) {
            throw ClientCreationApiException::duplicate($error);
        } catch (UniqueConstraintViolationException) {
            throw ClientCreationApiException::duplicateConstraint();
        } catch (QueryException $error) {
            if ($this->isUniqueConstraintViolation($error)) {
                throw ClientCreationApiException::duplicateConstraint();
            }

            throw $error;
        } catch (InvalidArgumentException $error) {
            throw ClientCreationApiException::invalidCnpj($error);
        } catch (ClientQuotaException $error) {
            throw ClientCreationApiException::quotaExceeded($error);
        }

        return new ClientCreationResult(
            client: $result['client'],
            establishment: $result['establishment'],
            contact: $result['contact'],
            customFields: $result['custom_fields'],
        );
    }

    private function isUniqueConstraintViolation(QueryException $error): bool
    {
        $sqlState = (string) ($error->errorInfo[0] ?? '');
        if ($sqlState === '23505') {
            return true;
        }

        $message = strtolower($error->getMessage());

        return str_contains($message, 'unique')
            || str_contains($message, 'duplicate');
    }
}
