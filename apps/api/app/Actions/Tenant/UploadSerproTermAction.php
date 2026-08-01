<?php

namespace App\Actions\Tenant;

use App\DTO\Tenant\SerproTermUploadData;
use App\Exceptions\TenantSerproAuthorizationApiException;
use App\Models\TenantSerproAuthorization;
use App\Services\Integra\TenantSerproAuthorizationService;
use App\Support\CurrentTenant;
use RuntimeException;
use Throwable;

final readonly class UploadSerproTermAction
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private TenantSerproAuthorizationService $authorizations,
    ) {}

    public function __invoke(
        SerproTermUploadData $data,
    ): TenantSerproAuthorization {
        try {
            $xml = $data->xml;
            if ($xml === null && $data->filePath !== null) {
                $xml = file_get_contents($data->filePath);
                if ($xml === false) {
                    throw new RuntimeException('Falha ao ler arquivo do Termo.');
                }
            }
            if ($xml === null || $xml === '') {
                throw new RuntimeException('Informe o XML ou um arquivo do Termo.');
            }

            return $this->authorizations->uploadTermo(
                $this->currentTenant->tenant(),
                $data->environment,
                $xml,
                $data->actorUserId,
            );
        } catch (RuntimeException $error) {
            throw TenantSerproAuthorizationApiException::operationFailed(
                $error->getMessage(),
            );
        } catch (Throwable $error) {
            report($error);

            throw TenantSerproAuthorizationApiException::termProcessingFailed();
        }
    }
}
