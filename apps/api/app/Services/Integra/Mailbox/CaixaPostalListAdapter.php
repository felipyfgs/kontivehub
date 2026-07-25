<?php

namespace App\Services\Integra\Mailbox;

use App\Contracts\CaixaPostalClient;
use App\Contracts\FiscalSourceAdapter;
use App\DTO\Fiscal\FiscalAdapterRequest;
use App\DTO\Fiscal\FiscalAdapterResult;
use App\DTO\Mailbox\CaixaPostalListResult;
use App\Enums\FiscalCoverage;
use App\Enums\FiscalFindingSeverity;
use App\Enums\FiscalMutability;
use App\Enums\FiscalRunResult;
use App\Enums\FiscalSituation;

/**
 * Adapter lista Caixa Postal — system/service/operation distintos do DTE.
 */
final class CaixaPostalListAdapter implements FiscalSourceAdapter
{
    public function __construct(
        private readonly CaixaPostalClient $client,
        private readonly MailboxMessageStore $store,
        private readonly MailboxDetailEnqueueService $detailEnqueue,
        private readonly MailboxSyncStateService $syncState,
    ) {}

    public function systemCode(): string
    {
        return 'INTEGRA_CAIXAPOSTAL';
    }

    public function serviceCode(): string
    {
        return 'CAIXA_POSTAL';
    }

    public function operationCode(): string
    {
        return 'LISTAR';
    }

    public function mutability(): FiscalMutability
    {
        return FiscalMutability::ReadOnly;
    }

    public function coverage(): FiscalCoverage
    {
        return FiscalCoverage::Full;
    }

    public function moduleKey(): ?string
    {
        return 'mailbox';
    }

    public function supports(FiscalAdapterRequest $request): bool
    {
        return strcasecmp($request->systemCode, $this->systemCode()) === 0
            && strcasecmp($request->serviceCode, $this->serviceCode()) === 0
            && (
                strcasecmp($request->operationCode, $this->operationCode()) === 0
                || strcasecmp($request->operationCode, 'MONITOR') === 0
            );
    }

    public function execute(FiscalAdapterRequest $request): FiscalAdapterResult
    {
        $maxPages = max(1, (int) config('fiscal_monitoring.mailbox.max_pages_per_sync', 20));
        $page = 0;
        $pointer = null;
        $seenPointers = [];
        $itemsByExternalId = [];
        $simulated = false;
        $sourceVersion = 'caixa-postal-serpro-v1';
        $lastMeta = [];
        $paginationComplete = false;

        do {
            $context = [
                'office_id' => $request->office->id,
                'client_id' => $request->client->id,
                'correlation_id' => $request->run->correlation_id,
                'status_leitura' => '0',
                'indicador_pagina' => $page === 0 ? '0' : '1',
            ];
            if ($pointer !== null) {
                $context['ponteiro_pagina'] = $pointer;
            }

            $list = $this->client->listMessages($context);
            if (! $list->success) {
                $this->store->markListError($request->office, $request->client, $request->run->id);
                $this->syncState->markListFailed(
                    $request->office,
                    $request->client,
                    $list->errorCode ?? 'MAILBOX_LIST_FAILED',
                );

                return FiscalAdapterResult::failed(
                    $list->errorMessage ?? 'Falha ao listar Caixa Postal.',
                    $list->errorCode ?? 'MAILBOX_LIST_FAILED',
                );
            }

            $page++;
            $simulated = $simulated || $list->simulated;
            $sourceVersion = $list->sourceVersion;
            $lastMeta = $list->rawMeta;

            foreach ($list->items as $item) {
                $externalId = trim((string) ($item['external_id'] ?? ''));
                if ($externalId !== '') {
                    $itemsByExternalId[$externalId] = $item;
                }
            }

            $lastPage = strtoupper(trim((string) ($list->rawMeta['indicador_ultima_pagina'] ?? '')));
            $nextPointer = trim((string) ($list->rawMeta['ponteiro_proxima_pagina'] ?? ''));
            if ($lastPage === 'S') {
                $paginationComplete = true;
                break;
            }
            if ($nextPointer === '') {
                if ($lastPage === 'N') {
                    $this->store->markListError($request->office, $request->client, $request->run->id);

                    return FiscalAdapterResult::failed(
                        'SERPRO indicou nova página sem fornecer o ponteiro de continuação.',
                        'MAILBOX_PAGINATION_CURSOR_MISSING',
                    );
                }

                // Compatibilidade com respostas antigas que não informavam paginação.
                $paginationComplete = true;
                break;
            }
            if ($page >= $maxPages) {
                break;
            }
            if (isset($seenPointers[$nextPointer])) {
                $this->store->markListError($request->office, $request->client, $request->run->id);

                return FiscalAdapterResult::failed(
                    'SERPRO repetiu o ponteiro de paginação da Caixa Postal.',
                    'MAILBOX_PAGINATION_LOOP',
                );
            }

            $seenPointers[$nextPointer] = true;
            $pointer = $nextPointer;
        } while ($page < $maxPages);

        $items = array_values($itemsByExternalId);
        $officialReadValues = array_values(array_filter(
            array_map(static fn (array $item) => $item['official_read'] ?? null, $items),
            static fn (mixed $value) => is_bool($value),
        ));
        $officialUnreadCount = $items === []
            ? 0
            : ($officialReadValues === [] ? null : count(array_filter(
                $officialReadValues,
                static fn (bool $read) => ! $read,
            )));
        $paginationTruncated = ! $paginationComplete;
        $list = new CaixaPostalListResult(
            success: true,
            items: $items,
            officialUnreadCount: $officialUnreadCount,
            simulated: $simulated,
            rawMeta: [
                ...$lastMeta,
                'pages_processed' => $page,
                'pagination_complete' => $paginationComplete,
                'pagination_truncated' => $paginationTruncated,
            ],
            sourceVersion: $sourceVersion,
        );

        $applied = $this->store->applyList(
            $request->office,
            $request->client,
            $list,
            $request->run->id,
        );

        $this->syncState->markListSucceeded(
            $request->office,
            $request->client,
            (bool) ($request->run->progress['mailbox_full_reconciliation'] ?? false),
        );

        $detailRuns = $this->detailEnqueue->enqueueAfterList($request->office, $request->client);

        $evidence = json_encode([
            'operation' => 'LISTAR',
            'source' => 'CAIXA_POSTAL',
            'simulated' => $list->simulated,
            'item_count' => count($list->items),
            'created' => $applied['created'],
            'updated' => $applied['updated'],
            'official_unread_count' => $list->officialUnreadCount,
            'pages_processed' => $page,
            'pagination_complete' => $paginationComplete,
            'pagination_truncated' => $paginationTruncated,
            'detail_fetches_enqueued' => count($detailRuns),
            // sem corpos/subjects
            'external_ids' => array_map(
                fn (array $i) => $i['external_id'] ?? null,
                $list->items
            ),
        ], JSON_THROW_ON_ERROR);

        $findings = [];
        if ($paginationTruncated) {
            $findings[] = [
                'code' => 'MAILBOX_PAGINATION_TRUNCATED',
                'severity' => FiscalFindingSeverity::Medium->value,
                'title' => 'Sincronização parcial da Caixa Postal',
                'detail' => sprintf('Limite de %d página(s) atingido.', $maxPages),
                'situation' => FiscalSituation::Unknown->value,
                'creates_pending' => false,
            ];
        }
        if (($list->officialUnreadCount ?? 0) > 0 || $applied['created'] > 0) {
            $findings[] = [
                'code' => 'MAILBOX_NEW_OR_UNREAD',
                'severity' => FiscalFindingSeverity::Medium->value,
                'title' => 'Mensagens na Caixa Postal',
                'detail' => sprintf(
                    '%d nova(s), unread oficial: %s',
                    $applied['created'],
                    $list->officialUnreadCount === null ? 'n/d' : (string) $list->officialUnreadCount
                ),
                'situation' => FiscalSituation::Attention->value,
                'creates_pending' => $applied['created'] > 0,
            ];
        }

        $situation = match (true) {
            $applied['created'] > 0, ($list->officialUnreadCount ?? 0) > 0 => FiscalSituation::Attention,
            $paginationTruncated => FiscalSituation::Unknown,
            default => FiscalSituation::UpToDate,
        };

        return new FiscalAdapterResult(
            result: FiscalRunResult::Success,
            situation: $situation,
            coverage: $paginationTruncated ? FiscalCoverage::Partial : FiscalCoverage::Full,
            evidenceBytes: $evidence,
            evidenceContentType: 'application/json',
            sourceVersion: $list->sourceVersion,
            normalized: [
                'source' => 'CAIXA_POSTAL',
                'created' => $applied['created'],
                'updated' => $applied['updated'],
                'official_unread_count' => $list->officialUnreadCount,
                'messages_status' => 'CONSULTED',
                'pagination_complete' => $paginationComplete,
                'detail_fetches_enqueued' => count($detailRuns),
            ],
            findings: $findings,
            itemsProcessed: count($list->items),
            pagesProcessed: $page,
        );
    }
}
