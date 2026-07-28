<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;

final class CommunicationFlowApiException extends ApiDomainException implements ShouldntReport
{
    public static function disabled(): self
    {
        return new self(
            'communication_flows_disabled',
            'Engine de fluxos desabilitada.',
            403,
        );
    }

    public static function flowVersionConflict(): self
    {
        return new self(
            'version_conflict',
            'Fluxo foi alterado por outro usuário.',
            409,
        );
    }

    public static function draftVersionConflict(): self
    {
        return new self(
            'version_conflict',
            'Draft foi alterado por outro usuário.',
            409,
        );
    }

    public static function bindingVersionConflict(): self
    {
        return new self(
            'version_conflict',
            'Binding foi alterado por outro usuário.',
            409,
        );
    }

    /** @param list<array{path: string, code: string, message: string}> $errors */
    public static function invalidGraph(array $errors, string $digest): self
    {
        return new self(
            'invalid_flow_graph',
            'Grafo de fluxo inválido.',
            422,
            [
                'graph_digest' => $digest,
                'errors' => $errors,
            ],
        );
    }

    public static function publishedVersionRequired(): self
    {
        return new self(
            'published_version_required',
            'Binding habilitado exige versão publicada.',
            422,
        );
    }

    public static function invalidPublishedVersion(): self
    {
        return new self(
            'invalid_published_version',
            'Versão publicada inválida para este fluxo.',
            422,
        );
    }

    public static function enabledBindingConflict(): self
    {
        return new self(
            'enabled_binding_conflict',
            'Já existe um binding habilitado para esta inbox.',
            409,
        );
    }

    public static function flowNameConflict(): self
    {
        return new self(
            'flow_name_conflict',
            'Já existe um fluxo com este nome.',
            409,
        );
    }

    /**
     * @param  array<string, mixed>  $responseData
     */
    private function __construct(
        string $code,
        string $message,
        int $status,
        array $responseData = [],
    ) {
        parent::__construct($code, $message, $status, $responseData);
    }
}
