<?php

namespace App\Enums\Communication;

enum ConversationListSort: string
{
    case LastActivityDesc = 'last_activity_desc';
    case LastActivityAsc = 'last_activity_asc';
    case CreatedDesc = 'created_desc';
    case CreatedAsc = 'created_asc';
    case UnreadDesc = 'unread_desc';
    case PriorityDesc = 'priority_desc';
    case PriorityAsc = 'priority_asc';

    public static function defaultPreference(): self
    {
        return self::LastActivityDesc;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
