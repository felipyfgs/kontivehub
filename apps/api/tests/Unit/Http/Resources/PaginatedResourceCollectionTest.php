<?php

namespace Tests\Unit\Http\Resources;

use App\Http\Resources\PaginatedResourceCollection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class PaginatedResourceCollectionTest extends TestCase
{
    #[Test]
    public function default_contract_preserves_laravel_links_metadata_and_query_parameters(): void
    {
        $request = Request::create('/api/v1/items?status=active', 'GET');
        $paginator = new LengthAwarePaginator(
            items: [['id' => 10], ['id' => 11]],
            total: 3,
            perPage: 2,
            currentPage: 1,
            options: [
                'path' => 'http://localhost/api/v1/items',
                'pageName' => 'page',
            ],
        );

        $response = (new DefaultPaginationCollection($paginator))
            ->preserveQuery()
            ->toResponse($request);
        $payload = $response->getData(true);

        self::assertSame(['data', 'links', 'meta'], array_keys($payload));
        self::assertSame([['id' => 10], ['id' => 11]], $payload['data']);
        self::assertSame(
            ['first', 'last', 'prev', 'next'],
            array_keys($payload['links']),
        );
        self::assertSame(
            'http://localhost/api/v1/items?status=active&page=1',
            $payload['links']['first'],
        );
        self::assertSame(
            'http://localhost/api/v1/items?status=active&page=2',
            $payload['links']['next'],
        );
        self::assertSame(1, $payload['meta']['current_page']);
        self::assertSame(2, $payload['meta']['last_page']);
        self::assertSame(2, $payload['meta']['per_page']);
        self::assertSame(3, $payload['meta']['total']);
        self::assertArrayHasKey('links', $payload['meta']);
    }
}

final class DefaultPaginationCollection extends PaginatedResourceCollection
{
    public $collects = PaginationItemResource::class;
}

final class PaginationItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->resource['id']];
    }
}
