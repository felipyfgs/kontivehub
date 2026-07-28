<?php

namespace App\Http\Requests\Outbound;

use App\DTO\Outbound\OutboundPackageUploadData;
use Illuminate\Http\UploadedFile;

final class UploadOutboundPackageRequest extends OperateOutboundRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'files' => ['required', 'array', 'min:1', 'max:20'],
            'files.*' => ['file', 'max:51200'],
        ];
    }

    public function packageData(): OutboundPackageUploadData
    {
        return new OutboundPackageUploadData(array_values(array_filter(
            $this->file('files', []),
            static fn (mixed $file): bool => $file instanceof UploadedFile,
        )));
    }
}
