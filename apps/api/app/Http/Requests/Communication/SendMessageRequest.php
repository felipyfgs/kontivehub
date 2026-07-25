<?php

namespace App\Http\Requests\Communication;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SendMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'body' => ['nullable', 'string', 'max:4096', 'required_without_all:file,location,contact,poll,interactive'],
            'file' => [
                'nullable',
                'file',
                'max:'.max(1, (int) ceil(((int) config('communication.media.max_bytes', 20_971_520)) / 1024)),
                'mimetypes:image/jpeg,image/png,image/webp,audio/ogg,audio/mpeg,audio/mp4,audio/webm,video/mp4,video/webm,application/pdf,text/plain,application/zip',
            ],
            'kind' => ['nullable', Rule::in([
                'TEXT', 'IMAGE', 'AUDIO', 'VIDEO', 'DOCUMENT', 'STICKER',
                'LOCATION', 'CONTACT', 'POLL', 'INTERACTIVE', 'UNSUPPORTED',
            ])],
            'ptt' => ['sometimes', 'boolean'],
            'gif' => ['sometimes', 'boolean'],
            'link_preview' => ['nullable', 'array:'.implode(',', ['url', 'title', 'description'])],
            'link_preview.url' => ['required_with:link_preview', 'url:http,https', 'max:2048'],
            'link_preview.title' => ['nullable', 'string', 'max:512'],
            'link_preview.description' => ['nullable', 'string', 'max:2048'],
            'location' => ['nullable', 'array:latitude,longitude,name,address'],
            'location.latitude' => ['required_with:location', 'numeric', 'between:-90,90'],
            'location.longitude' => ['required_with:location', 'numeric', 'between:-180,180'],
            'location.name' => ['nullable', 'string', 'max:512'],
            'location.address' => ['nullable', 'string', 'max:2048'],
            'contact' => ['nullable', 'array:display_name,vcard'],
            'contact.display_name' => ['required_with:contact', 'string', 'max:512'],
            'contact.vcard' => ['required_with:contact', 'string', 'max:65536'],
            'poll' => ['nullable', 'array:name,options,selectable_options'],
            'poll.name' => ['required_with:poll', 'string', 'max:1024'],
            'poll.options' => ['required_with:poll', 'array', 'min:2', 'max:12'],
            'poll.options.*' => ['required', 'string', 'distinct', 'max:512'],
            'poll.selectable_options' => ['required_with:poll', 'integer', 'min:1', 'max:12'],
            'interactive' => ['nullable', 'array:mode,title,description,options'],
            'interactive.mode' => ['required_with:interactive', Rule::in(['BUTTONS', 'LIST'])],
            'interactive.title' => ['nullable', 'string', 'max:512'],
            'interactive.description' => ['nullable', 'string', 'max:2048'],
            'interactive.options' => ['required_with:interactive', 'array', 'min:1', 'max:20'],
            'interactive.options.*' => ['required', 'string', 'distinct', 'max:512'],
            'reply_to_message_id' => ['nullable', 'integer', 'min:1'],
            'internal_note' => ['sometimes', 'boolean'],
            'idempotency_key' => ['nullable', 'string', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/'],
        ];
    }
}
