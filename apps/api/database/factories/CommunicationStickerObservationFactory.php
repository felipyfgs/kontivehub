<?php

namespace Database\Factories;

use App\Enums\Communication\StickerAvailability;
use App\Enums\Communication\StickerSource;
use App\Models\CommunicationInbox;
use App\Models\CommunicationStickerContent;
use App\Models\CommunicationStickerObservation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<CommunicationStickerObservation> */
class CommunicationStickerObservationFactory extends Factory
{
    protected $model = CommunicationStickerObservation::class;

    public function definition(): array
    {
        return [
            'tenant_id' => fn (array $attributes) => CommunicationStickerContent::query()->find($attributes['content_id'])?->tenant_id,
            'inbox_id' => CommunicationInbox::factory(),
            'content_id' => CommunicationStickerContent::factory(),
            'public_id' => (string) Str::ulid(),
            'observation_id' => 'factory:'.Str::ulid(),
            'source' => StickerSource::LocalImport,
            'availability' => StickerAvailability::Available,
            'last_observed_at' => now(),
        ];
    }
}
