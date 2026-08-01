<?php

namespace App\Console\Commands;

use App\Jobs\Communication\ReconcileInboxIdentityProfilesJob;
use App\Models\CommunicationInbox;
use Illuminate\Console\Command;

final class ReconcileCommunicationInboxIdentityProfilesCommand extends Command
{
    protected $signature = 'communication:reconcile-inbox-contact-profiles {--tenant=} {--inbox=}';

    protected $description = 'Agenda a reconciliação local e limitada de perfis WhatsApp já conhecidos.';

    public function handle(): int
    {
        $query = CommunicationInbox::query()->withoutGlobalScopes()->orderBy('id');
        if ($this->option('tenant') !== null) {
            $query->where('tenant_id', (int) $this->option('tenant'));
        }
        if ($this->option('inbox') !== null) {
            $query->whereKey((int) $this->option('inbox'));
        }
        $inboxes = $query->get(['id', 'tenant_id']);
        foreach ($inboxes as $inbox) {
            ReconcileInboxIdentityProfilesJob::dispatch((int) $inbox->tenant_id, (int) $inbox->id);
        }
        $this->info($inboxes->count().' reconciliações de perfil agendadas.');

        return self::SUCCESS;
    }
}
