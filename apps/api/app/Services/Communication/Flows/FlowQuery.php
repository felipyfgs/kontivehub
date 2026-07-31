<?php

namespace App\Services\Communication\Flows;

use App\Models\CommunicationFlow;
use App\Models\CommunicationFlowDraft;
use App\Models\CommunicationFlowInboxBinding;
use Illuminate\Database\Eloquent\Collection;

final class FlowQuery
{
    /** @return Collection<int, CommunicationFlow> */
    public function all(): Collection
    {
        return CommunicationFlow::query()
            ->orderBy('name')
            ->get();
    }

    public function detail(CommunicationFlow $flow): CommunicationFlow
    {
        return $flow->loadMissing([
            'draft',
            'versions',
            'bindings',
        ]);
    }

    public function draft(CommunicationFlow $flow): CommunicationFlowDraft
    {
        return $flow->draft()->firstOrFail();
    }

    /** @return Collection<int, CommunicationFlowInboxBinding> */
    public function bindings(CommunicationFlow $flow): Collection
    {
        return $flow->bindings()
            ->orderBy('id')
            ->get();
    }
}
