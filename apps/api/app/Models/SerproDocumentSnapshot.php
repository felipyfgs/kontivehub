<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'source_key',
    'title',
    'url',
    'content_sha256',
    'document_type',
    'revision',
    'retrieved_on',
    'affected_capabilities',
    'segregation_class',
    'notes',
    'metadata',
])]
class SerproDocumentSnapshot extends Model
{
    protected function casts(): array
    {
        return [
            'retrieved_on' => 'date',
            'affected_capabilities' => 'array',
            'metadata' => 'array',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toSanitizedArray(): array
    {
        return [
            'id' => $this->id,
            'source_key' => $this->source_key,
            'title' => $this->title,
            'url' => $this->url,
            'content_sha256' => $this->content_sha256,
            'document_type' => $this->document_type,
            'revision' => $this->revision,
            'retrieved_on' => $this->retrieved_on?->toDateString(),
            'affected_capabilities' => $this->affected_capabilities ?? [],
            'segregation_class' => $this->segregation_class,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
