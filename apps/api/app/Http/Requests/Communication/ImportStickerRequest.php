<?php

namespace App\Http\Requests\Communication;

use App\Models\CommunicationInbox;
use App\Models\User;
use App\Services\Communication\Authorization\Access;
use Illuminate\Http\UploadedFile;

final class ImportStickerRequest extends TenantScopedRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User
            && $this->route('inbox') instanceof CommunicationInbox
            && app(Access::class)->canReply($this->user(), $this->route('inbox'));
    }

    public function rules(): array
    {
        return [
            'file' => [
                'required', 'file',
                'max:'.max(1, (int) ceil(((int) config('communication.sticker_library.max_item_bytes', 1_048_576)) / 1024)),
                'mimetypes:image/webp',
            ],
        ];
    }

    public function upload(): UploadedFile
    {
        /** @var UploadedFile $upload */
        $upload = $this->file('file');

        return $upload;
    }
}
