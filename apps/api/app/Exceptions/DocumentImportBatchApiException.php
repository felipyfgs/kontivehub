<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;

final class DocumentImportBatchApiException extends ApiDomainException implements ShouldntReport
{
    public static function admissionRejected(string $message): self
    {
        return new self(
            'document_import_batch_rejected',
            $message,
            422,
        );
    }

    public static function admissionFailed(): self
    {
        return new self(
            'document_import_batch_failed',
            'Falha ao admitir lote de importação.',
            422,
        );
    }

    public static function retryRejected(string $message): self
    {
        return new self(
            'document_import_item_retry_rejected',
            $message,
            422,
        );
    }

    private function __construct(string $code, string $message, int $status)
    {
        parent::__construct($code, $message, $status);
    }
}
