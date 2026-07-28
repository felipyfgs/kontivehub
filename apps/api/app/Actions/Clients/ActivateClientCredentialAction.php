<?php

namespace App\Actions\Clients;

use App\DTO\Clients\ClientCredentialActivationData;
use App\Exceptions\ClientCredentialApiException;
use App\Models\Client;
use App\Models\ClientCredential;
use App\Services\Audit\AuditLogger;
use App\Services\Certificates\CredentialService;
use App\Support\LogSanitizer;
use RuntimeException;
use Throwable;

final readonly class ActivateClientCredentialAction
{
    public function __construct(
        private CredentialService $credentials,
        private AuditLogger $audit,
    ) {}

    public function activeFor(Client $client): ?ClientCredential
    {
        return $this->credentials->activeFor($client);
    }

    public function activate(
        Client $client,
        ClientCredentialActivationData $data,
    ): ClientCredential {
        try {
            $credential = $this->credentials->activate(
                $client,
                $data->pfxBinary,
                $data->password,
            );
        } catch (RuntimeException $error) {
            $message = LogSanitizer::scrubString($error->getMessage());
            $this->audit->record('credential.activate', 'FAILED', $client, [
                'message' => $message !== '' ? $message : 'Falha ao ativar certificado.',
            ]);

            throw ClientCredentialApiException::activationFailed($error);
        } catch (Throwable $error) {
            report($error);
            $this->audit->record('credential.activate', 'FAILED', $client, [
                'message' => 'Falha ao ativar certificado.',
            ]);

            throw ClientCredentialApiException::unexpectedFailure();
        }

        $this->audit->record('credential.activate', 'SUCCESS', $credential, [
            'client_id' => $client->id,
            'fingerprint_sha256' => $credential->fingerprint_sha256,
            'valid_to' => $credential->valid_to?->toIso8601String(),
        ]);

        return $credential;
    }
}
