<?php

namespace Database\Factories;

use App\Enums\Communication\StickerSource;
use App\Models\CommunicationStickerContent;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<CommunicationStickerContent> */
class CommunicationStickerContentFactory extends Factory
{
    protected $model = CommunicationStickerContent::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'public_id' => (string) Str::ulid(),
            'sha256' => hash('sha256', fake()->uuid()),
            'object_id_encrypted' => (string) Str::ulid(),
            'storage_context_encrypted' => ['tenant_id' => 1, 'inbox_id' => 1, 'sticker_import_id' => (string) Str::ulid()],
            'mime_type' => 'image/webp',
            'size_bytes' => 512,
            'width' => 512,
            'height' => 512,
            'animated' => false,
            'provenance' => StickerSource::LocalImport,
            'retention_protected' => true,
        ];
    }
}
