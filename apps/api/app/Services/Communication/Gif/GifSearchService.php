<?php

namespace App\Services\Communication\Gif;

use App\Contracts\GifSearchProvider;
use App\Models\CommunicationInbox;
use App\Models\User;
use App\Services\Communication\Authorization\Access;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;

final readonly class GifSearchService
{
    public function __construct(
        private GifSearchProvider $provider,
        private Access $access,
        private Factory $http,
    ) {}

    /** @return list<array{id:string,title:string,preview_path:string,asset_path:string,asset_token:string}> */
    public function search(User $actor, CommunicationInbox $inbox, string $query, int $limit): array
    {
        $this->access->assertReply($actor, $inbox);
        $ttl = max(10, (int) config('communication.gif_provider.cache_ttl_seconds', 120));
        $key = 'communication:gif-search:'.$inbox->tenant_id.':'.$inbox->id.':'
            .hash('sha256', mb_strtolower($query).'|'.$limit);

        return Cache::remember($key, $ttl, function () use ($query, $limit, $inbox, $ttl): array {
            return array_map(function ($result) use ($inbox, $ttl): array {
                $token = Str::random(40);
                Cache::put('communication:gif-asset:'.$inbox->tenant_id.':'.$token, [
                    'inbox_id' => $inbox->id,
                    'preview_url' => $result->previewUrl,
                    'media_url' => $result->mediaUrl,
                ], $ttl);

                return [
                    'id' => $result->providerId,
                    'title' => $result->title,
                    'preview_path' => '/api/v1/communication/gifs/'.$token.'/preview',
                    'asset_path' => '/api/v1/communication/gifs/'.$token.'/asset',
                    'asset_token' => $token,
                ];
            }, $this->provider->search($query, $limit));
        });
    }

    /**
     * @return array{body:string,mime:string}|null
     */
    public function fetchAsset(string $url, bool $preview = false): ?array
    {
        if (! $this->isAllowedUrl($url)) {
            return null;
        }

        if ($preview) {
            $response = $this->http->connectTimeout(2)
                ->timeout(5)
                ->withOptions(['allow_redirects' => false])
                ->get($url);
        } else {
            $response = $this->http
                ->connectTimeout((int) config('communication.gif_provider.connect_timeout_seconds', 2))
                ->timeout((int) config('communication.gif_provider.timeout_seconds', 5))
                ->withOptions(['allow_redirects' => false])
                ->retry([100, 250], throw: false)
                ->get($url);
        }
        if (! $response->successful()) {
            throw new RuntimeException('GIF_ASSET_UNAVAILABLE');
        }

        return [
            'body' => $response->body(),
            'mime' => strtolower(trim(explode(';', (string) $response->header('Content-Type'), 2)[0] ?? '')),
        ];
    }

    private function isAllowedUrl(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return parse_url($url, PHP_URL_SCHEME) === 'https'
            && in_array($host, array_map('strtolower', (array) config('communication.gif_provider.allowed_hosts', [])), true);
    }
}
