<?php

namespace App\Services\Esocial;

use App\Contracts\EsocialBxSoapTransport;
use App\Contracts\EsocialEventClient;
use App\DTO\Esocial\EsocialBxDownloadResult;
use App\DTO\Esocial\EsocialBxIdentifiersResult;
use App\DTO\Esocial\EsocialFetchRequest;
use App\DTO\Esocial\EsocialFetchResult;
use App\Enums\EsocialEventCode;
use App\Exceptions\EsocialBxException;
use App\Models\EsocialBxAccessLedger;
use Throwable;

final class HttpEsocialBxEventClient implements EsocialEventClient
{
    public function __construct(
        private readonly EsocialBxReadinessService $readiness,
        private readonly EsocialBxCredentialResolver $credentials,
        private readonly EsocialBxAccessGuard $guard,
        private readonly EsocialBxRequestFactory $requests,
        private readonly EsocialBxResponseParser $parser,
        private readonly EsocialBxSoapTransport $transport,
    ) {}

    public function fetchEvents(EsocialFetchRequest $request): EsocialFetchResult
    {
        $readiness = $this->readiness->check($request->office, $request->client);
        if (! $readiness->ready) {
            $blocker = $readiness->blockers[0] ?? [
                'code' => 'ESOCIAL_BX_NOT_READY',
                'message' => 'Provider eSocial BX indisponível.',
            ];

            return EsocialFetchResult::failed($blocker['message'], $blocker['code']);
        }

        $environment = $readiness->environment;
        $requestedByCode = [];
        foreach ($request->resolvedEventCodes() as $eventCode) {
            $requestedByCode[$eventCode->value] = $eventCode;
        }
        $requested = array_values($requestedByCode);
        $automatic = array_values(array_filter(
            $requested,
            static fn (EsocialEventCode $code): bool => in_array($code, [EsocialEventCode::S1299, EsocialEventCode::S5013], true),
        ));

        if ($automatic === []) {
            return new EsocialFetchResult(
                events: [],
                success: true,
                partial: $requested !== [],
                diagnostics: [
                    'source' => 'ESOCIAL_BX_OFFICIAL',
                    'environment' => $environment,
                    'automatic_events' => [],
                ],
            );
        }

        try {
            return $this->guard->withEmployerLock(
                $request->client,
                $environment,
                function () use ($request, $environment, $automatic, $requested): EsocialFetchResult {
                    $material = $this->credentials->material($request->office, $request->client);
                    $events = [];
                    $partial = count($automatic) !== count($requested);

                    try {
                        foreach ($automatic as $eventCode) {
                            $identifiers = $this->fetchIdentifiers(
                                $request,
                                $environment,
                                $eventCode,
                                $material['pfx'],
                                $material['password'],
                            );
                            $partial = $partial || $identifiers->partial;
                            if ($identifiers->identifiers === []) {
                                continue;
                            }

                            $downloads = $this->fetchDownloads(
                                $request,
                                $environment,
                                $eventCode,
                                $identifiers,
                                $material['pfx'],
                                $material['password'],
                            );
                            array_push($events, ...$downloads->events);
                            $partial = $partial || $downloads->partial;
                        }
                    } finally {
                        $material['pfx'] = str_repeat("\0", strlen($material['pfx']));
                        $material['password'] = str_repeat("\0", strlen($material['password']));
                        unset($material);
                    }

                    $deduplicated = [];
                    foreach ($events as $event) {
                        $eventIdHash = $event->metadata['event_id_hash'] ?? null;
                        $key = $event->eventCode->value.':'.(
                            is_string($eventIdHash) && $eventIdHash !== ''
                                ? $eventIdHash
                                : $event->contentSha256()
                        );
                        $deduplicated[$key] = $event;
                    }

                    return new EsocialFetchResult(
                        events: array_values($deduplicated),
                        success: true,
                        partial: $partial,
                        diagnostics: [
                            'source' => 'ESOCIAL_BX_OFFICIAL',
                            'environment' => $environment,
                            'automatic_events' => array_map(static fn (EsocialEventCode $code) => $code->value, $automatic),
                        ],
                    );
                },
            );
        } catch (EsocialBxException $e) {
            return new EsocialFetchResult(
                events: [],
                success: false,
                errorCode: $e->stableCode,
                errorMessage: $e->getMessage(),
                diagnostics: [
                    'retryable' => $e->retryable,
                    'blocked' => $e->blocked,
                    'environment' => $environment,
                    'official_code' => $e->officialCode,
                ],
            );
        } catch (Throwable) {
            return EsocialFetchResult::failed(
                'Falha interna sanitizada ao consultar o eSocial BX.',
                'ESOCIAL_BX_INTERNAL_ERROR',
            );
        }
    }

    private function fetchIdentifiers(
        EsocialFetchRequest $request,
        string $environment,
        EsocialEventCode $eventCode,
        string $pfx,
        string $password,
    ): EsocialBxIdentifiersResult {
        $soap = $this->requests->identifiers(
            $environment,
            (string) $request->client->root_cnpj,
            $eventCode,
            $request->competencePeriodKey,
            $pfx,
            $password,
        );

        return $this->execute(
            $request,
            $environment,
            $soap,
            $pfx,
            $password,
            fn (string $body): EsocialBxIdentifiersResult => $this->parser->identifiers($body),
        );
    }

    private function fetchDownloads(
        EsocialFetchRequest $request,
        string $environment,
        EsocialEventCode $eventCode,
        EsocialBxIdentifiersResult $identifiers,
        string $pfx,
        string $password,
    ): EsocialBxDownloadResult {
        $soap = $this->requests->downloadByIds(
            $environment,
            (string) $request->client->root_cnpj,
            $identifiers->ids(),
            $pfx,
            $password,
        );

        return $this->execute(
            $request,
            $environment,
            $soap,
            $pfx,
            $password,
            fn (string $body): EsocialBxDownloadResult => $this->parser->downloads(
                $body,
                $eventCode,
                $request->competencePeriodKey,
                $identifiers->receiptsById(),
            ),
        );
    }

    /** @param array{operation:string,endpoint:string,soap_action:string,envelope:string} $soap
     *  @template T of EsocialBxIdentifiersResult|EsocialBxDownloadResult
     *
     * @param  callable(string):T  $parse
     * @return T
     */
    private function execute(
        EsocialFetchRequest $request,
        string $environment,
        array $soap,
        string $pfx,
        string $password,
        callable $parse,
    ): mixed {
        $entry = $this->guard->reserve(
            $request->office,
            $request->client,
            $environment,
            $soap['operation'],
            $request->correlationId,
        );

        try {
            $response = $this->transport->post(
                $soap['endpoint'],
                $soap['soap_action'],
                $soap['envelope'],
                $pfx,
                $password,
            );
            if ($response->status < 200 || $response->status >= 300) {
                throw new EsocialBxException(
                    'ESOCIAL_BX_HTTP_ERROR',
                    'O endpoint eSocial BX retornou erro HTTP.',
                    retryable: $response->status >= 500 || $response->status === 429,
                    httpStatus: $response->status,
                );
            }
            $parsed = $parse($response->body);
            $this->guard->finish(
                $entry,
                'SUCCEEDED',
                $response->status,
                $parsed->officialCode,
            );

            return $parsed;
        } catch (EsocialBxException $e) {
            $this->finishFailed($entry, $e);
            throw $e;
        } catch (Throwable $e) {
            $failure = new EsocialBxException(
                'ESOCIAL_BX_RESPONSE_FAILED',
                'Falha sanitizada ao processar a resposta eSocial BX.',
                retryable: true,
                previous: $e,
            );
            $this->finishFailed($entry, $failure);
            throw $failure;
        }
    }

    private function finishFailed(EsocialBxAccessLedger $entry, EsocialBxException $e): void
    {
        $this->guard->finish(
            $entry,
            $e->blocked ? 'BLOCKED' : 'FAILED',
            $e->httpStatus,
            $e->officialCode,
            $e->retryable,
        );
    }
}
