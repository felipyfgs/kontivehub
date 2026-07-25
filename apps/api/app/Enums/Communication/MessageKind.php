<?php

namespace App\Enums\Communication;

enum MessageKind: string
{
    case Text = 'TEXT';
    case Image = 'IMAGE';
    case Audio = 'AUDIO';
    case Video = 'VIDEO';
    case Document = 'DOCUMENT';
    case Sticker = 'STICKER';
    case Location = 'LOCATION';
    case Contact = 'CONTACT';
    case Poll = 'POLL';
    case Interactive = 'INTERACTIVE';
    case Unsupported = 'UNSUPPORTED';
    case Note = 'NOTE';
}
