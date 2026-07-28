<?php

namespace App\Services\Communication;

use App\Models\CommunicationContact;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Resolve e bloqueia redirects de contatos no mesmo tenant.
 */
final class CommunicationContactCanonicalizer
{
    private const MAX_REDIRECTS = 16;

    public function contact(CommunicationContact $contact): CommunicationContact
    {
        $tenantId = (int) $contact->tenant_id;
        $current = $contact;
        $visited = [];

        for ($depth = 0; $depth < self::MAX_REDIRECTS; $depth++) {
            if (isset($visited[$current->id])) {
                throw new LogicException('Ciclo detectado no redirecionamento de contact.');
            }
            $visited[$current->id] = true;
            $targetId = $current->merged_into_contact_id !== null
                ? (int) $current->merged_into_contact_id
                : (int) $current->id;
            $current = CommunicationContact::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->findOrFail($targetId);
            if ($current->merged_into_contact_id === null) {
                return $current;
            }
        }

        throw new LogicException('Cadeia de redirecionamento de contact excede o limite.');
    }

    /**
     * Bloqueia toda a classe em ordem determinística e devolve o único destino final.
     *
     * @return array{0:CommunicationContact,1:list<int>}
     */
    public function lockContactClass(CommunicationContact $contact): array
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('O bloqueio de contact canônico exige uma transação ativa.');
        }

        $tenantId = (int) $contact->tenant_id;
        $ids = [(int) $contact->id];
        $lockedIds = [];

        for ($depth = 0; $depth < self::MAX_REDIRECTS; $depth++) {
            $ids = $this->expandIds($tenantId, $ids);
            $newIds = array_values(array_diff($ids, $lockedIds));
            sort($newIds, SORT_NUMERIC);
            if ($newIds !== []) {
                CommunicationContact::query()
                    ->withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->whereIn('id', $newIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                $lockedIds = array_values(array_unique([...$lockedIds, ...$newIds]));
                sort($lockedIds, SORT_NUMERIC);
            }

            $expandedIds = $this->expandIds($tenantId, $lockedIds);
            if ($expandedIds !== $lockedIds) {
                $ids = $expandedIds;

                continue;
            }

            $contacts = CommunicationContact::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereIn('id', $lockedIds)
                ->orderBy('id')
                ->get();
            $roots = $contacts->whereNull('merged_into_contact_id')->values();
            if ($roots->count() !== 1) {
                throw new LogicException('Classe de contacts sem destino canônico único.');
            }

            return [$roots->first(), $lockedIds];
        }

        throw new LogicException('Classe de contacts mudou continuamente durante o bloqueio.');
    }

    /**
     * @param  list<int>  $seedIds
     * @return list<int>
     */
    private function expandIds(int $tenantId, array $seedIds): array
    {
        $ids = array_values(array_unique($seedIds));
        sort($ids, SORT_NUMERIC);

        for ($depth = 0; $depth < self::MAX_REDIRECTS; $depth++) {
            /** @var Collection<int,CommunicationContact> $rows */
            $rows = CommunicationContact::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where(static fn ($query) => $query
                    ->whereIn('id', $ids)
                    ->orWhereIn('merged_into_contact_id', $ids))
                ->get(['id', 'merged_into_contact_id']);
            $expanded = $ids;
            foreach ($rows as $row) {
                $expanded[] = (int) $row->id;
                if ($row->merged_into_contact_id !== null) {
                    $expanded[] = (int) $row->merged_into_contact_id;
                }
            }
            $expanded = array_values(array_unique($expanded));
            sort($expanded, SORT_NUMERIC);
            if ($expanded === $ids) {
                return $ids;
            }
            $ids = $expanded;
        }

        throw new LogicException('Classe de contacts excede o limite de canonicalização.');
    }
}
