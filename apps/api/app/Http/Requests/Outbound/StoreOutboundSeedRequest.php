<?php

namespace App\Http\Requests\Outbound;

use App\DTO\Outbound\OutboundSeedData;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class StoreOutboundSeedRequest extends OperateOutboundRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'environment' => ['required', Rule::in(['production', 'homologation'])],
            'xml' => ['required_without:file', 'string'],
            'file' => ['required_without:xml', 'file', 'max:5120'],
        ];
    }

    public function seedData(): OutboundSeedData
    {
        $validated = $this->validated();
        $xml = isset($validated['xml']) ? (string) $validated['xml'] : null;
        if ($xml === null) {
            $file = $this->file('file');
            $path = $file instanceof UploadedFile ? $file->getRealPath() : false;
            $xml = is_string($path) && $path !== '' ? file_get_contents($path) : false;
        }
        if (! is_string($xml) || $xml === '') {
            throw ValidationException::withMessages([
                'file' => ['Não foi possível ler o XML enviado.'],
            ]);
        }

        return new OutboundSeedData(
            environment: (string) $validated['environment'],
            xml: $xml,
        );
    }
}
