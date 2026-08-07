<?php

namespace Tests\Unit\Communication;

use App\Services\Communication\Media\MediaStore;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

final class MediaStoreRangeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('communication_media');
        $this->app->forgetInstance(MediaStore::class);
        config(['communication.media.disk' => 'communication_media']);
    }

    public function test_validated_range_returns_only_requested_bytes_in_chunks(): void
    {
        $bytes = str_repeat('abcdefgh', 20_000);
        $metadata = ['tenant_id' => 1, 'inbox_id' => 2];
        $media = app(MediaStore::class);
        $stored = $media->putStream(Utils::streamFor($bytes), $metadata);

        $generator = $media->readValidatedRange(
            $stored['object_id'],
            $metadata,
            0,
            70_000,
            $stored['size_bytes'],
            $stored['sha256'],
        );
        $chunks = iterator_to_array($generator);

        $this->assertSame(substr($bytes, 0, 70_001), implode('', $chunks));
        $this->assertGreaterThan(1, count($chunks));
    }

    public function test_validated_range_rejects_digest_and_size_before_first_yield(): void
    {
        $bytes = 'conteúdo com integridade';
        $metadata = ['tenant_id' => 1, 'inbox_id' => 2];
        $media = app(MediaStore::class);
        $stored = $media->putStream(Utils::streamFor($bytes), $metadata);

        $badDigest = $media->readValidatedRange(
            $stored['object_id'],
            $metadata,
            0,
            5,
            $stored['size_bytes'],
            hash('sha256', 'outro conteúdo'),
        );
        try {
            $badDigest->current();
            $this->fail('O range deveria rejeitar digest divergente antes do primeiro yield.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }

        $badSize = $media->readValidatedRange(
            $stored['object_id'],
            $metadata,
            0,
            5,
            $stored['size_bytes'] + 1,
            $stored['sha256'],
        );
        try {
            $badSize->current();
            $this->fail('O range deveria rejeitar tamanho divergente antes do primeiro yield.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }
    }
}
