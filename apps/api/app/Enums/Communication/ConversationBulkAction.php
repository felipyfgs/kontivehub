<?php

namespace App\Enums\Communication;

enum ConversationBulkAction: string
{
    case SetStatus = 'SET_STATUS';
    case SetAssignee = 'SET_ASSIGNEE';
    case SetDepartment = 'SET_DEPARTMENT';
    case AddLabels = 'ADD_LABELS';
    case RemoveLabels = 'REMOVE_LABELS';
    case MarkRead = 'MARK_READ';
    case MarkUnread = 'MARK_UNREAD';

    public function requiresReplyPermission(): bool
    {
        return ! in_array($this, [self::MarkRead, self::MarkUnread], true);
    }

    public function requiresLockVersion(): bool
    {
        return in_array($this, [
            self::SetStatus,
            self::SetAssignee,
            self::SetDepartment,
        ], true);
    }

    public function requiresThroughMessageId(): bool
    {
        return $this === self::MarkRead;
    }

    public function requiresReadStateVersion(): bool
    {
        return $this === self::MarkUnread;
    }
}
