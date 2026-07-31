<?php

namespace App\Services\Communication\Canned;

use App\Exceptions\CommunicationCannedResponseApiException;
use Illuminate\Database\QueryException;

final class CannedResponseConflictMapper
{
    private const SHORTCUT_CONSTRAINT = 'communication_canned_responses_tenant_id_shortcut_unique';

    public function throwIfShortcutConflict(QueryException $error): void
    {
        $databaseMessage = (string) ($error->errorInfo[2] ?? '');

        if ((string) $error->getCode() === '23505'
            && str_contains($databaseMessage, self::SHORTCUT_CONSTRAINT)) {
            throw CommunicationCannedResponseApiException::shortcutConflict();
        }
    }
}
