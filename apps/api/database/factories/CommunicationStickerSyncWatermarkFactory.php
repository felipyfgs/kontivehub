<?php

namespace Database\Factories;

use App\Enums\Communication\StickerSyncStatus;
use App\Models\CommunicationInbox;
use App\Models\CommunicationStickerSyncWatermark;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CommunicationStickerSyncWatermark> */
class CommunicationStickerSyncWatermarkFactory extends Factory
{
    protected $model = CommunicationStickerSyncWatermark::class;

    public function definition(): array
    {
        return [
            'tenant_id' => fn (array $attributes) => CommunicationInbox::query()->find($attributes['inbox_id'])?->tenant_id,
            'inbox_id' => CommunicationInbox::factory(),
            'status' => StickerSyncStatus::NotObserved,
        ];
    }
}
