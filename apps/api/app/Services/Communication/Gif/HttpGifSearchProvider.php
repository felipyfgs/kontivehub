<?php

namespace App\Services\Communication\Gif;

use App\Contracts\GifSearchProvider;
use App\DTO\Communication\GifProviderResultData;
use Illuminate\Http\Client\Factory;
use RuntimeException;

final readonly class HttpGifSearchProvider implements GifSearchProvider
{
    public function __construct(private Factory $http) {}

    public function search(string $query, int $limit): array
    {
        $baseUrl = rtrim((string) config('communication.gif_provider.base_url'), '/');
        $response = $this->http->baseUrl($baseUrl)
            ->acceptJson()
            ->withToken((string) config('communication.gif_provider.api_key'))
            ->connectTimeout((int) config('communication.gif_provider.connect_timeout_seconds', 2))
            ->timeout((int) config('communication.gif_provider.timeout_seconds', 5))
            ->withOptions(['allow_redirects' => false])
            ->retry([100, 250], throw: false)
            ->get('/search', ['q' => $query, 'limit' => $limit]);
        if (! $response->successful() || ! is_array($response->json('data'))) {
            throw new RuntimeException('GIF_PROVIDER_UNAVAILABLE');
        }

        $results = [];
        foreach (array_slice($response->json('data'), 0, $limit) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $id = mb_substr(trim((string) ($item['id'] ?? '')), 0, 128);
            $preview = (string) ($item['preview_url'] ?? '');
            $media = (string) ($item['media_url'] ?? '');
            if ($id === '' || ! $this->allowedUrl($preview) || ! $this->allowedUrl($media)) {
                continue;
            }
            $results[] = new GifProviderResultData(
                providerId: $id,
                title: mb_substr(trim((string) ($item['title'] ?? 'GIF')), 0, 200),
                previewUrl: $preview,
                mediaUrl: $media,
            );
        }

        return $results;
    }

    private function allowedUrl(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return parse_url($url, PHP_URL_SCHEME) === 'https'
            && in_array($host, array_map('strtolower', (array) config('communication.gif_provider.allowed_hosts', [])), true);
    }
}
