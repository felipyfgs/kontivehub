<?php

namespace App\Http\Resources\Communication;

use App\DTO\Communication\ConversationListPageData;
use App\Http\Resources\PaginatedResourceCollection;
use Illuminate\Http\Request;

final class ConversationCollection extends PaginatedResourceCollection
{
    /** @var class-string */
    public $collects = ConversationResource::class;

    public function __construct(
        ConversationListPageData $page,
    ) {
        $this->snapshotToken = $page->snapshotToken;
        $this->snapshotExpiresAt = $page->snapshotExpiresAt;

        parent::__construct($page->paginator);
    }

    private ?string $snapshotToken;

    private ?string $snapshotExpiresAt;

    /**
     * @param  array<string, mixed>  $paginated
     * @param  array{links: array<string, mixed>, meta: array<string, mixed>}  $default
     * @return array<string, mixed>
     */
    public function paginationInformation(Request $request, array $paginated, array $default): array
    {
        $information = parent::paginationInformation($request, $paginated, $default);
        if ($this->snapshotToken !== null && $this->snapshotExpiresAt !== null) {
            $information['meta']['snapshot_token'] = $this->snapshotToken;
            $information['meta']['snapshot_expires_at'] = $this->snapshotExpiresAt;
        }

        return $information;
    }

    /** @return list<string> */
    protected function paginationMetaFields(): array
    {
        return [
            'current_page',
            'last_page',
            'total',
        ];
    }

    protected function includesPaginationLinks(): bool
    {
        return false;
    }
}
