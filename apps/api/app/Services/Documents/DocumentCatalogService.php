<?php

namespace App\Services\Documents;

use App\Enums\CaptureChannel;
use App\Enums\DocumentArtifactQuality;
use App\Enums\DocumentDirection;
use App\Enums\DocumentKind;
use App\Enums\FiscalRole;
use App\Models\CteDocument;
use App\Models\DocumentAcquisition;
use App\Models\NfeDocument;
use App\Models\NfseNote;
use App\Support\CurrentOffice;
use App\Support\DocumentCatalogCursor;
use App\Support\NfseNoteStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Catálogo unificado de documentos (NFS-e / NF-e / NFC-e / CT-e):
 * listagem, filtros, insights, agregação por cliente e serialização de itens.
 *
 * Retorna payloads de array; o controller monta a JsonResponse HTTP.
 */
class DocumentCatalogService
{
    /**
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function index(Request $request, CurrentOffice $currentOffice): array
    {
        $officeId = $currentOffice->office()->id;
        $kinds = DocumentKind::listFromRequest($request);
        $wantNfse = DocumentKind::includesNfse($kinds);
        $wantNfe = DocumentKind::includes($kinds, DocumentKind::Nfe);
        $wantNfce = DocumentKind::includes($kinds, DocumentKind::Nfce);
        $wantCte = DocumentKind::includes($kinds, DocumentKind::Cte);
        // MDF-e é reconhecido apenas como filtro legado: não há query/projeção operacional.
        $wantSefazProjection = $wantNfe || $wantNfce;
        // Kind exclusivo sem implementação/fonte (incluindo MDFE legado) → vazio sem tocar no banco.
        if (! $wantNfse && ! $wantSefazProjection && ! $wantCte) {
            return [
                'data' => [],
                'meta' => [
                    'next_cursor' => null,
                    'total' => 0,
                    'per_page' => min(max((int) $request->input('limit', 25), 1), 100),
                ],
            ];
        }

        $limit = min(max((int) $request->input('limit', 25), 1), 100);
        try {
            $cursor = DocumentCatalogCursor::fromToken($request->string('cursor')->toString() ?: null);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'cursor' => ['Informe um cursor retornado pela API.'],
            ])->errorBag('Cursor do catálogo inválido.');
        }
        $rows = collect();

        if ($wantNfse) {
            $q = NfseNote::query()->where('office_id', $officeId)->orderByDesc('id');
            if ($beforeId = $cursor->beforeId(DocumentKind::Nfse)) {
                $q->where('id', '<', $beforeId);
            }
            $this->applyCatalogFilters($q, $request);
            $rows = $rows->merge(
                $q->limit($limit + 1)->get()->map(fn (NfseNote $n) => $this->serializeNoteListItem($n))
            );
        }

        if ($wantSefazProjection) {
            $q = NfeDocument::query()->where('office_id', $officeId)->orderByDesc('id');
            if ($beforeId = $cursor->beforeId(DocumentKind::Nfe)) {
                $q->where('id', '<', $beforeId);
            }
            $this->applyNfeCatalogFilters($q, $request);
            // Filtro de modelo quando kind é exclusivo NFE ou NFCE
            if ($wantNfe && ! $wantNfce && $kinds !== []) {
                $q->where(function ($inner): void {
                    $inner->where('model', '55')->orWhereNull('model');
                });
            } elseif ($wantNfce && ! $wantNfe && $kinds !== []) {
                $q->where('model', '65');
            }
            // Nunca listar resumo se o full da mesma chave existe (preferência de entrega).
            $q->where(function ($inner): void {
                $inner->where('is_summary', false)
                    ->orWhereNotExists(function ($sub): void {
                        $sub->select(DB::raw(1))
                            ->from('nfe_documents as full_nfe')
                            ->whereColumn('full_nfe.access_key', 'nfe_documents.access_key')
                            ->whereColumn('full_nfe.office_id', 'nfe_documents.office_id')
                            ->where('full_nfe.is_summary', false);
                    });
            });
            $nfeModels = $q->with(['document.interests', 'document.acquisitions'])->limit($limit + 1)->get();
            $fullKeys = $this->fullAccessKeysForNfe($nfeModels);
            $rows = $rows->merge(
                $nfeModels->map(fn (NfeDocument $n) => $this->serializeNfeListItem($n, $fullKeys))
            );
        }

        if ($wantCte) {
            $q = CteDocument::query()->where('office_id', $officeId)->orderByDesc('id');
            if ($beforeId = $cursor->beforeId(DocumentKind::Cte)) {
                $q->where('id', '<', $beforeId);
            }
            $this->applyCteCatalogFilters($q, $request);
            // Visão por cliente: autoriza apenas documentos com interesse no client_id.
            // Visão ampla: office_id explícito + BelongsToOffice.
            $cteModels = $q->with(['document.interests', 'document.acquisitions'])->limit($limit + 1)->get();
            $fullCteKeys = $this->fullAccessKeysForCte($cteModels);
            $rows = $rows->merge(
                $cteModels->map(fn (CteDocument $n) => $this->serializeCteListItem($n, $fullCteKeys))
            );
        }

        // Ordenação estável; o cursor mantém uma posição independente por fonte.
        $sorted = $rows->sortByDesc(fn (array $r) => (int) ($r['id'] ?? 0))->values();
        $hasMore = $sorted->count() > $limit;
        $page = $hasMore ? $sorted->take($limit) : $sorted;
        $nextCursor = $hasMore ? $cursor->advance($page)->toToken() : null;

        return [
            'data' => $page->values()->all(),
            'meta' => [
                'next_cursor' => $nextCursor,
                'total' => $this->catalogTotal($request, $officeId, $wantNfse, $wantSefazProjection, $wantNfe, $wantNfce, $wantCte, $kinds),
                'per_page' => $limit,
            ],
        ];
    }

    /**
     * Total no escopo dos filtros (soma por fonte). Usado na UPagination do catálogo.
     * Contagem espelha as cláusulas de listagem (incl. preferência full sobre resumo NF-e/CT-e).
     *
     * @param  list<DocumentKind>  $kinds
     */
    private function catalogTotal(
        Request $request,
        int $officeId,
        bool $wantNfse,
        bool $wantSefazProjection,
        bool $wantNfe,
        bool $wantNfce,
        bool $wantCte,
        array $kinds,
    ): int {
        $total = 0;

        if ($wantNfse) {
            $q = NfseNote::query()->where('office_id', $officeId);
            $this->applyCatalogFilters($q, $request);
            $total += (int) $q->count();
        }

        if ($wantSefazProjection) {
            $q = NfeDocument::query()->where('office_id', $officeId);
            $this->applyNfeCatalogFilters($q, $request);
            if ($wantNfe && ! $wantNfce && $kinds !== []) {
                $q->where(function ($inner): void {
                    $inner->where('model', '55')->orWhereNull('model');
                });
            } elseif ($wantNfce && ! $wantNfe && $kinds !== []) {
                $q->where('model', '65');
            }
            $q->where(function ($inner): void {
                $inner->where('is_summary', false)
                    ->orWhereNotExists(function ($sub): void {
                        $sub->select(DB::raw(1))
                            ->from('nfe_documents as full_nfe')
                            ->whereColumn('full_nfe.access_key', 'nfe_documents.access_key')
                            ->whereColumn('full_nfe.office_id', 'nfe_documents.office_id')
                            ->where('full_nfe.is_summary', false);
                    });
            });
            $total += (int) $q->count();
        }

        if ($wantCte) {
            $q = CteDocument::query()->where('office_id', $officeId);
            $this->applyCteCatalogFilters($q, $request);
            // Listagem CT-e aplica preferência full/resumo no merge em memória, não no SQL.
            $total += (int) $q->count();
        }

        return $total;
    }

    /**
     * Contagens de triagem no escopo dos filtros (chips clicáveis).
     * Facetas de status/competência ignoram o próprio campo para o operador ver o recorte.
     *
     * @return array{data: array<string, mixed>}
     */
    public function insights(Request $request, CurrentOffice $currentOffice): array
    {
        $officeId = $currentOffice->office()->id;
        $currentCompetence = now()->format('Y-m');
        $kinds = DocumentKind::listFromRequest($request);
        $byKind = [
            'NFSE' => DocumentKind::includesNfse($kinds)
                ? NfseNote::query()->where('office_id', $officeId)->count()
                : 0,
            'NFE' => DocumentKind::includes($kinds, DocumentKind::Nfe)
                ? NfeDocument::query()->where('office_id', $officeId)->where(function ($q): void {
                    $q->where('model', '55')->orWhereNull('model');
                })->count()
                : 0,
            'NFCE' => DocumentKind::includes($kinds, DocumentKind::Nfce)
                ? NfeDocument::query()->where('office_id', $officeId)->where('model', '65')->count()
                : 0,
            'CTE' => DocumentKind::includes($kinds, DocumentKind::Cte)
                ? CteDocument::query()->where('office_id', $officeId)->count()
                : 0,
        ];

        if (! DocumentKind::includesNfse($kinds)) {
            return [
                'data' => [
                    'total' => array_sum($byKind),
                    'active' => 0,
                    'cancelled' => 0,
                    'superseded' => 0,
                    'substitute' => 0,
                    'review' => 0,
                    'missing_party_name' => 0,
                    'competence_current' => 0,
                    'competence_current_label' => $currentCompetence,
                    'by_kind' => $byKind,
                ],
            ];
        }

        $scoped = NfseNote::query()->where('office_id', $officeId);
        $this->applyCatalogFilters($scoped, $request);

        $withoutStatus = NfseNote::query()->where('office_id', $officeId);
        $this->applyCatalogFilters($withoutStatus, $request, ignoreClientId: false, ignoreStatus: true);

        $withoutCompetence = NfseNote::query()->where('office_id', $officeId);
        $this->applyCatalogFilters($withoutCompetence, $request, ignoreClientId: false, ignoreStatus: false, ignoreCompetence: true);

        $missingParty = (clone $scoped)->where(function ($q): void {
            $q->where(function ($inner): void {
                $inner->whereNull('issuer_name')->orWhere('issuer_name', '');
            })->orWhere(function ($inner): void {
                $inner->whereNull('taker_name')->orWhere('taker_name', '');
            });
        });

        $authorizedStatuses = NfseNoteStatus::statusesInGroup(NfseNoteStatus::GROUP_AUTHORIZED);
        $cancelledStatuses = NfseNoteStatus::statusesInGroup(NfseNoteStatus::GROUP_CANCELLED);
        $reviewStatuses = NfseNoteStatus::statusesInGroup(NfseNoteStatus::GROUP_REVIEW);

        return [
            'data' => [
                'total' => (clone $scoped)->count(),
                // Grupo operacional Autorizada (ACTIVE + SUBSTITUTE + JUDICIAL)
                'active' => (clone $withoutStatus)->whereIn('status', $authorizedStatuses)->count(),
                // Grupo operacional Cancelada (CANCELLED + SUPERSEDED)
                'cancelled' => (clone $withoutStatus)->whereIn('status', $cancelledStatuses)->count(),
                'superseded' => (clone $withoutStatus)->whereIn('status', ['SUPERSEDED', 'REPLACED'])->count(),
                'substitute' => (clone $withoutStatus)->where('status', 'SUBSTITUTE')->count(),
                // Em revisão = situação indefinida
                'review' => (clone $withoutStatus)->whereIn('status', $reviewStatuses)->count(),
                'missing_party_name' => $missingParty->count(),
                'competence_current' => (clone $withoutCompetence)->where('competence', $currentCompetence)->count(),
                'competence_current_label' => $currentCompetence,
                'by_kind' => $byKind,
            ],
        ];
    }

    /**
     * Agregação por cliente do escritório (aba "Por empresa").
     * Conta notas distintas com interesse em estabelecimento do cliente, no escopo dos filtros.
     *
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function byClient(Request $request, CurrentOffice $currentOffice): array
    {
        $officeId = $currentOffice->office()->id;

        if (! DocumentKind::includesNfse(DocumentKind::listFromRequest($request))) {
            return [
                'data' => [],
                'meta' => ['total_clients' => 0],
            ];
        }

        $notesQuery = NfseNote::query()->where('office_id', $officeId);
        $this->applyCatalogFilters($notesQuery, $request, ignoreClientId: true);
        $noteIds = $notesQuery->select('nfse_notes.id');

        // Uma linha por (nota, cliente) — evita multiplicar valor por vários interests no mesmo cliente.
        $pairs = DB::table('document_interests as di')
            ->join('dfe_documents as d', 'd.id', '=', 'di.dfe_document_id')
            ->join('nfse_notes as n', 'n.dfe_document_id', '=', 'd.id')
            ->join('establishments as e', 'e.id', '=', 'di.establishment_id')
            ->where('n.office_id', $officeId)
            ->whereIn('n.id', $noteIds)
            ->select([
                'e.client_id',
                'n.id as note_id',
                'n.service_amount',
                'n.status',
                'n.issued_at',
            ])
            ->distinct();

        $cancelledStatuses = NfseNoteStatus::statusesInGroup(NfseNoteStatus::GROUP_CANCELLED);
        $cancelledPlaceholders = implode(', ', array_fill(0, count($cancelledStatuses), '?'));
        $perPage = min(max((int) $request->input('per_page', 20), 1), 100);

        $paginator = DB::query()
            ->fromSub($pairs, 'p')
            ->join('clients as c', function ($join) use ($officeId): void {
                $join->on('c.id', '=', 'p.client_id')
                    ->where('c.office_id', '=', $officeId);
            })
            ->select([
                'c.id as client_id',
                'c.legal_name',
                'c.display_name',
                'c.root_cnpj',
            ])
            ->selectRaw('COALESCE(c.display_name, c.legal_name) as name')
            ->selectRaw('(SELECT e2.cnpj FROM establishments e2 WHERE e2.client_id = c.id ORDER BY e2.id LIMIT 1) as cnpj')
            ->selectRaw('COUNT(*) as notes_count')
            ->selectRaw('COALESCE(SUM(p.service_amount), 0) as service_amount_sum')
            ->selectRaw("SUM(CASE WHEN p.status IN ($cancelledPlaceholders) THEN 1 ELSE 0 END) as cancelled_count", $cancelledStatuses)
            ->selectRaw("SUM(CASE WHEN p.status = 'UNKNOWN' THEN 1 ELSE 0 END) as review_count")
            ->selectRaw('MAX(p.issued_at) as last_issued_at')
            ->groupBy('c.id', 'c.legal_name', 'c.display_name', 'c.root_cnpj')
            ->orderByDesc('review_count')
            ->orderByDesc('cancelled_count')
            ->orderByDesc('notes_count')
            ->orderBy('c.legal_name')
            ->paginate($perPage);

        $data = collect($paginator->items())->map(static function ($row): array {
            return [
                'client_id' => (int) $row->client_id,
                'legal_name' => $row->legal_name,
                'display_name' => $row->display_name,
                'name' => $row->name,
                'root_cnpj' => $row->root_cnpj,
                'cnpj' => $row->cnpj,
                'notes_count' => (int) $row->notes_count,
                'service_amount_sum' => number_format((float) $row->service_amount_sum, 2, '.', ''),
                'cancelled_count' => (int) $row->cancelled_count,
                'review_count' => (int) $row->review_count,
                'last_issued_at' => $row->last_issued_at
                    ? (new CarbonImmutable((string) $row->last_issued_at))->toIso8601String()
                    : null,
            ];
        });

        return [
            'data' => $data->values()->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'total_clients' => $paginator->total(),
            ],
        ];
    }

    /**
     * Filtros compartilhados entre listagem, insights e agregação por cliente.
     *
     * @param  Builder<NfseNote>  $query
     */
    private function applyCatalogFilters(
        Builder $query,
        Request $request,
        bool $ignoreClientId = false,
        bool $ignoreStatus = false,
        bool $ignoreCompetence = false,
    ): void {
        // Busca de triagem: número, nomes, CNPJ (parcial) ou chave.
        $search = $request->string('q')->toString();
        if ($search === '') {
            $search = $request->string('access_key')->toString();
        }
        if ($search !== '') {
            $needle = '%'.mb_strtolower($search).'%';
            $cnpjNeedle = '%'.strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $search) ?? $search).'%';
            $query->where(function ($q) use ($needle, $cnpjNeedle, $search): void {
                $q->whereRaw('LOWER(access_key) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(COALESCE(number, \'\')) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(COALESCE(issuer_name, \'\')) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(COALESCE(taker_name, \'\')) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(COALESCE(intermediary_name, \'\')) LIKE ?', [$needle])
                    ->orWhere('issuer_cnpj', 'like', $cnpjNeedle)
                    ->orWhere('taker_cnpj', 'like', $cnpjNeedle)
                    ->orWhere('intermediary_cnpj', 'like', $cnpjNeedle)
                    ->orWhere('access_key', $search);
            });
        }
        if ($v = $request->string('issuer_cnpj')->toString()) {
            $query->where('issuer_cnpj', strtoupper(preg_replace('/\W/', '', $v) ?? ''));
        }
        if ($v = $request->string('taker_cnpj')->toString()) {
            $query->where('taker_cnpj', strtoupper(preg_replace('/\W/', '', $v) ?? ''));
        }
        if (! $ignoreCompetence && ($v = $request->string('competence')->toString())) {
            $query->where('competence', $v);
        }
        if (! $ignoreStatus && ($v = $request->string('status')->toString())) {
            $statuses = NfseNoteStatus::statusesForFilter($v);
            if (count($statuses) === 1) {
                $query->where('status', $statuses[0]);
            } elseif (count($statuses) > 1) {
                $query->whereIn('status', $statuses);
            }
        }
        if ($v = $request->string('fiscal_role')->toString()) {
            $query->where('fiscal_role', $v);
        }
        if ($direction = DocumentDirection::tryFromRequest($request->string('direction')->toString())) {
            $query->where('direction', $direction->value);
        }
        if (! $ignoreClientId && ($clientId = $request->integer('client_id'))) {
            $query->whereHas('document.interests.establishment', function ($interest) use ($clientId): void {
                $interest->where('client_id', $clientId);
            });
        }
        if ($establishmentId = $request->integer('establishment_id')) {
            $query->whereHas('document.interests', function ($interest) use ($establishmentId): void {
                $interest->where('establishment_id', $establishmentId);
            });
        }
        if ($from = $request->string('issued_from')->toString()) {
            $query->whereDate('issued_at', '>=', $from);
        }
        if ($to = $request->string('issued_to')->toString()) {
            $query->whereDate('issued_at', '<=', $to);
        }
        // Fila de triagem: falta nome de emitente ou tomador.
        if ($request->boolean('missing_party_name')) {
            $query->where(function ($q): void {
                $q->where(function ($inner): void {
                    $inner->whereNull('issuer_name')->orWhere('issuer_name', '');
                })->orWhere(function ($inner): void {
                    $inner->whereNull('taker_name')->orWhere('taker_name', '');
                });
            });
        }
    }

    /**
     * Projeção legível para triagem — sem XML, vault ou metadados sensíveis.
     *
     * @return array<string, mixed>
     */
    public function serializeNoteListItem(NfseNote $note): array
    {
        $cStat = $note->official_status_code;

        $kind = DocumentKind::Nfse;

        return [
            'id' => $note->id,
            'kind' => $kind->value,
            'kind_label' => $kind->label(),
            'source' => $kind->defaultSource(),
            'capture_available' => $kind->captureAvailable(),
            'access_key' => $note->access_key,
            'number' => $note->number,
            'issuer_cnpj' => $note->issuer_cnpj,
            'issuer_name' => $note->issuer_name,
            'taker_cnpj' => $note->taker_cnpj,
            'taker_name' => $note->taker_name,
            'intermediary_cnpj' => $note->intermediary_cnpj,
            'intermediary_name' => $note->intermediary_name,
            'fiscal_role' => $note->fiscal_role?->value ?? $note->fiscal_role,
            'direction' => ($dir = $note->direction ?? DocumentDirection::fromFiscalRole(
                $note->fiscal_role instanceof FiscalRole ? $note->fiscal_role : null
            ))->value,
            'direction_label' => $dir->label(),
            'competence' => $note->competence,
            'issued_at' => $note->issued_at?->toIso8601String(),
            'service_amount' => $note->service_amount,
            'issue_location' => $note->issue_location,
            'service_location' => $note->service_location,
            'status' => $note->status,
            'status_label' => NfseNoteStatus::label((string) $note->status),
            'official_status_code' => $cStat,
            'official_status_label' => NfseNoteStatus::officialDescription(
                (string) $note->status,
                $cStat
            ),
        ];
    }

    /**
     * @param  array<string, true>  $fullKeys  access_keys com full irmão (para linhas resumo)
     * @return array<string, mixed>
     */
    public function serializeNfeListItem(NfeDocument $doc, array $fullKeys = []): array
    {
        $kind = ($doc->model === '65') ? DocumentKind::Nfce : DocumentKind::Nfe;
        $isSummary = (bool) $doc->is_summary;
        $hasFull = ! $isSummary || isset($fullKeys[$doc->access_key]);

        $interests = $doc->relationLoaded('document') && $doc->document?->relationLoaded('interests')
            ? $doc->document->interests
            : collect();
        $acquisitions = $doc->relationLoaded('document') && $doc->document?->relationLoaded('acquisitions')
            ? $doc->document->acquisitions
            : collect();

        $interestPayload = $interests->map(function ($i) {
            return [
                'establishment_id' => $i->establishment_id,
                'fiscal_role' => $i->fiscal_role instanceof FiscalRole ? $i->fiscal_role->value : $i->fiscal_role,
                'direction' => $i->direction instanceof DocumentDirection ? $i->direction->value : $i->direction,
                'channel' => $i->channel,
                // NSU só quando real (não sintético/null)
                'nsu' => $i->nsu,
            ];
        })->values()->all();

        $acquisitionSources = $acquisitions->map(function ($a) {
            $src = $a->source;
            $value = is_object($src) && property_exists($src, 'value') ? $src->value : (string) $src;

            return $value;
        })->unique()->values()->all();

        // Proveniência: aquisições > schema_hint import > default do kind.
        // NFC-e (65) nunca rotulada como AUTXML.
        $source = $kind->defaultSource();
        if ($acquisitionSources !== []) {
            $source = $acquisitionSources[0];
            if ($kind === DocumentKind::Nfce) {
                $acquisitionSources = array_values(array_filter(
                    $acquisitionSources,
                    fn ($s) => ! str_contains((string) $s, 'AUTXML')
                ));
                $source = $acquisitionSources[0] ?? 'IMPORT';
            }
        } elseif (str_starts_with((string) $doc->schema_hint, 'import:')) {
            $source = 'MANUAL_XML';
        } elseif ($doc->acquisition_source) {
            $src = $doc->acquisition_source;
            $source = is_object($src) && property_exists($src, 'value') ? $src->value : (string) $src;
            if ($kind === DocumentKind::Nfce && str_contains($source, 'AUTXML')) {
                $source = 'MANUAL_XML';
            }
        }

        $directions = collect($interestPayload)
            ->pluck('direction')
            ->filter()
            ->unique()
            ->values()
            ->all();
        $primaryDirection = $doc->direction?->value
            ?? ($directions[0] ?? DocumentDirection::In->value);

        return [
            'id' => $doc->id,
            'kind' => $kind->value,
            'kind_label' => $kind->label(),
            'source' => $source,
            'acquisition_sources' => $acquisitionSources !== [] ? $acquisitionSources : [$source],
            'capture_available' => $kind->captureAvailable(),
            'access_key' => $doc->access_key,
            'number' => $doc->number,
            'issuer_cnpj' => $doc->issuer_cnpj,
            'issuer_name' => $doc->issuer_name,
            'taker_cnpj' => $doc->recipient_cnpj,
            'taker_name' => $doc->recipient_name,
            'intermediary_cnpj' => null,
            'intermediary_name' => null,
            'fiscal_role' => $doc->fiscal_role?->value ?? $doc->fiscal_role,
            'direction' => $primaryDirection,
            'direction_label' => (DocumentDirection::tryFrom((string) $primaryDirection) ?? DocumentDirection::In)->label(),
            'directions' => $directions !== [] ? $directions : [$primaryDirection],
            'interests' => $interestPayload,
            'multi_role' => count($interestPayload) > 1,
            'competence' => $doc->issued_at?->format('Y-m'),
            'issued_at' => $doc->issued_at?->toIso8601String(),
            'service_amount' => $doc->total_amount,
            'issue_location' => null,
            'service_location' => null,
            'status' => $doc->status,
            // Situação fiscal operacional (lista): Autorizada · Cancelada · Em revisão
            'status_label' => $this->nfeFiscalStatusLabel((string) $doc->status, $doc->official_status_code),
            'official_status_code' => $doc->official_status_code,
            'official_status_label' => $this->nfeOfficialStatusLabel(
                (string) $doc->status,
                $doc->official_status_code,
                $isSummary && ! $hasFull,
            ),
            'is_summary' => $isSummary,
            'has_full_xml' => $hasFull,
            'xml_completeness' => $hasFull ? 'FULL' : 'SUMMARY_ONLY',
            'xml_completeness_label' => ($isSummary && ! $hasFull) ? 'Somente resumo' : 'XML completo',
            'manifestation_status' => $doc->manifestation_status,
        ];
    }

    /**
     * @param  array<string, true>  $fullKeys
     * @return array<string, mixed>
     */
    public function serializeCteListItem(CteDocument $doc, array $fullKeys = []): array
    {
        $kind = DocumentKind::Cte;
        $isSummary = (bool) $doc->is_summary;
        $hasFull = ! $isSummary || isset($fullKeys[$doc->access_key]);

        // Preferir acquisitions eager-loaded (index()); fallback query só sem relation.
        if ($doc->relationLoaded('document') && $doc->document?->relationLoaded('acquisitions')) {
            $acquisition = $doc->document->acquisitions
                ->sortBy([
                    ['is_canonical', 'desc'],
                    ['id', 'desc'],
                ])
                ->first();
        } else {
            $acquisition = DocumentAcquisition::query()
                ->where('office_id', $doc->office_id)
                ->where('access_key', $doc->access_key)
                ->orderByDesc('is_canonical')
                ->orderByDesc('id')
                ->first();
        }

        $quality = $acquisition?->artifact_quality;
        $signature = $acquisition?->signature_result;
        $isRedacted = $quality === DocumentArtifactQuality::AutXmlRedacted;

        $interests = $doc->relationLoaded('document') && $doc->document?->relationLoaded('interests')
            ? $doc->document->interests
            : collect();
        $interestPayload = $interests->map(function ($i) {
            return [
                'establishment_id' => $i->establishment_id,
                'fiscal_role' => $i->fiscal_role instanceof FiscalRole ? $i->fiscal_role->value : $i->fiscal_role,
                'direction' => $i->direction instanceof DocumentDirection ? $i->direction->value : $i->direction,
                'channel' => $i->channel instanceof CaptureChannel
                    ? $i->channel->value
                    : $i->channel,
                'nsu' => $i->nsu,
            ];
        })->values()->all();

        return [
            'id' => $doc->id,
            'kind' => $kind->value,
            'kind_label' => $kind->label(),
            'source' => $kind->defaultSource(),
            'capture_available' => $kind->captureAvailable(),
            'access_key' => $doc->access_key,
            'number' => $doc->number,
            'series' => $doc->series,
            'model' => $doc->model,
            'issuer_cnpj' => $doc->issuer_cnpj,
            'issuer_name' => $doc->issuer_name,
            'taker_cnpj' => $doc->taker_cnpj,
            'taker_name' => $doc->taker_name,
            'effective_taker_cnpj' => $doc->effective_taker_cnpj,
            'sender_cnpj' => $doc->sender_cnpj,
            'recipient_cnpj' => $doc->recipient_cnpj,
            'expeditor_cnpj' => $doc->expeditor_cnpj,
            'expeditor_name' => $doc->expeditor_name,
            'receiver_cnpj' => $doc->receiver_cnpj,
            'receiver_name' => $doc->receiver_name,
            'intermediary_cnpj' => null,
            'intermediary_name' => null,
            'fiscal_role' => $doc->fiscal_role?->value ?? $doc->fiscal_role,
            'fiscal_role_label' => $doc->fiscal_role?->label(),
            'direction' => $doc->direction?->value ?? DocumentDirection::In->value,
            'direction_label' => ($doc->direction ?? DocumentDirection::In)->label(),
            'interests' => $interestPayload,
            'multi_role' => count($interestPayload) > 1,
            'acquisition_source' => $acquisition?->source?->value,
            'acquisition_source_label' => $acquisition?->source?->label(),
            'artifact_quality' => $quality?->value,
            'artifact_quality_label' => $quality?->label(),
            'signature_result' => $signature?->value,
            'signature_result_label' => $signature?->label(),
            'is_autxml_redacted' => $isRedacted,
            'autxml_redacted_notice' => $isRedacted
                ? 'Cópia autXML com referências oficiais substituídas por 999… — solicite o original ao emissor se necessário.'
                : null,
            'coverage_status' => $doc->coverage_status?->value,
            'coverage_status_label' => $doc->coverage_status?->label(),
            'schema_version' => $doc->schema_version,
            'competence' => $doc->issued_at?->format('Y-m'),
            'issued_at' => $doc->issued_at?->toIso8601String(),
            'service_amount' => $doc->total_amount,
            'issue_location' => null,
            'service_location' => null,
            'status' => $doc->status,
            'status_label' => $this->nfeFiscalStatusLabel((string) $doc->status, $doc->official_status_code),
            'official_status_code' => $doc->official_status_code,
            'official_status_label' => $this->nfeOfficialStatusLabel(
                (string) $doc->status,
                $doc->official_status_code,
                $isSummary && ! $hasFull,
            ),
            'is_summary' => $isSummary,
            'has_full_xml' => $hasFull,
            'xml_completeness' => $hasFull ? 'FULL' : 'SUMMARY_ONLY',
            'xml_completeness_label' => ($isSummary && ! $hasFull) ? 'Somente resumo' : 'XML completo',
        ];
    }

    /**
     * @param  Builder<CteDocument>  $query
     */
    private function applyCteCatalogFilters(Builder $query, Request $request): void
    {
        $search = $request->string('q')->toString();
        if ($search === '') {
            $search = $request->string('access_key')->toString();
        }
        if ($search !== '') {
            $needle = '%'.mb_strtolower($search).'%';
            $cnpjNeedle = '%'.strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $search) ?? $search).'%';
            $query->where(function ($q) use ($needle, $cnpjNeedle, $search): void {
                $q->whereRaw('LOWER(access_key) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(COALESCE(number, \'\')) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(COALESCE(issuer_name, \'\')) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(COALESCE(taker_name, \'\')) LIKE ?', [$needle])
                    ->orWhere('issuer_cnpj', 'like', $cnpjNeedle)
                    ->orWhere('taker_cnpj', 'like', $cnpjNeedle)
                    ->orWhere('access_key', $search);
            });
        }
        if ($v = $request->string('issuer_cnpj')->toString()) {
            $query->where('issuer_cnpj', strtoupper(preg_replace('/\W/', '', $v) ?? ''));
        }
        if ($v = $request->string('taker_cnpj')->toString()) {
            $query->where('taker_cnpj', strtoupper(preg_replace('/\W/', '', $v) ?? ''));
        }
        if ($v = $request->string('status')->toString()) {
            $query->where('status', strtoupper($v));
        }
        if ($v = $request->string('fiscal_role')->toString()) {
            $role = strtoupper($v);
            $query->where(function ($q) use ($role): void {
                $q->where('fiscal_role', $role)
                    ->orWhereHas('document.interests', fn ($i) => $i->where('fiscal_role', $role));
            });
        }
        if ($v = $request->string('acquisition_source')->toString()) {
            $source = strtoupper($v);
            $query->whereHas('document.acquisitions', function ($acquisition) use ($source): void {
                $acquisition->where('source', $source);
            });
        }
        if ($v = $request->string('artifact_quality')->toString()) {
            $quality = strtoupper($v);
            $query->whereHas('document', function ($dfe) use ($quality): void {
                $dfe->whereIn('id', function ($sub) use ($quality): void {
                    $sub->select('dfe_document_id')
                        ->from('document_acquisitions')
                        ->where('artifact_quality', $quality)
                        ->where('is_canonical', true);
                });
            });
        }
        if ($v = $request->string('coverage_status')->toString()) {
            $query->where('coverage_status', strtoupper($v));
        }
        if ($direction = DocumentDirection::tryFromRequest($request->string('direction')->toString())) {
            // Direção via interesse (fonte de verdade por estabelecimento) ou projeção legada.
            $query->where(function ($q) use ($direction): void {
                $q->where('direction', $direction->value)
                    ->orWhereHas('document.interests', function ($interest) use ($direction): void {
                        $interest->where('direction', $direction->value);
                    });
            });
        }
        // Visão por cliente: autoriza por interesse (fonte de verdade multi-papel).
        // Não usar só CNPJ de projeção — evita vazar docs de outro cliente do mesmo office.
        if ($clientId = $request->integer('client_id')) {
            $query->whereHas('document.interests.establishment', function ($interest) use ($clientId): void {
                $interest->where('client_id', $clientId);
            });
        }
        if ($establishmentId = $request->integer('establishment_id')) {
            $query->whereHas('document.interests', function ($interest) use ($establishmentId): void {
                $interest->where('establishment_id', $establishmentId);
            });
        }
        if ($from = $request->string('issued_from')->toString()) {
            $query->whereDate('issued_at', '>=', $from);
        }
        if ($to = $request->string('issued_to')->toString()) {
            $query->whereDate('issued_at', '<=', $to);
        }
    }

    /**
     * @param  Builder<NfeDocument>  $query
     */
    private function applyNfeCatalogFilters(Builder $query, Request $request): void
    {
        $search = $request->string('q')->toString();
        if ($search === '') {
            $search = $request->string('access_key')->toString();
        }
        if ($search !== '') {
            $needle = '%'.mb_strtolower($search).'%';
            $cnpjNeedle = '%'.strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $search) ?? $search).'%';
            $query->where(function ($q) use ($needle, $cnpjNeedle, $search): void {
                $q->whereRaw('LOWER(access_key) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(COALESCE(number, \'\')) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(COALESCE(issuer_name, \'\')) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(COALESCE(recipient_name, \'\')) LIKE ?', [$needle])
                    ->orWhere('issuer_cnpj', 'like', $cnpjNeedle)
                    ->orWhere('recipient_cnpj', 'like', $cnpjNeedle)
                    ->orWhere('access_key', $search);
            });
        }
        if ($v = $request->string('issuer_cnpj')->toString()) {
            $query->where('issuer_cnpj', strtoupper(preg_replace('/\W/', '', $v) ?? ''));
        }
        if ($v = $request->string('taker_cnpj')->toString()) {
            $query->where('recipient_cnpj', strtoupper(preg_replace('/\W/', '', $v) ?? ''));
        }
        if ($v = $request->string('status')->toString()) {
            $query->where('status', strtoupper($v));
        }
        if ($direction = DocumentDirection::tryFromRequest($request->string('direction')->toString())) {
            $query->where(function ($q) use ($direction): void {
                $q->where('direction', $direction->value)
                    ->orWhereHas('document.interests', function ($interest) use ($direction): void {
                        $interest->where('direction', $direction->value);
                    });
            });
        }
        if ($clientId = $request->integer('client_id')) {
            $query->where(function ($q) use ($clientId): void {
                $q->whereHas('document.interests.establishment', function ($interest) use ($clientId): void {
                    $interest->where('client_id', $clientId);
                })->orWhereIn('issuer_cnpj', function ($sub) use ($clientId): void {
                    $sub->select('cnpj')
                        ->from('establishments')
                        ->where('client_id', $clientId);
                })->orWhereIn('recipient_cnpj', function ($sub) use ($clientId): void {
                    $sub->select('cnpj')
                        ->from('establishments')
                        ->where('client_id', $clientId);
                });
            });
        }
        if ($establishmentId = $request->integer('establishment_id')) {
            $query->where(function ($q) use ($establishmentId): void {
                $q->whereHas('document.interests', function ($interest) use ($establishmentId): void {
                    $interest->where('establishment_id', $establishmentId);
                })->orWhereIn('issuer_cnpj', function ($sub) use ($establishmentId): void {
                    $sub->select('cnpj')->from('establishments')->where('id', $establishmentId);
                })->orWhereIn('recipient_cnpj', function ($sub) use ($establishmentId): void {
                    $sub->select('cnpj')->from('establishments')->where('id', $establishmentId);
                });
            });
        }
        if ($from = $request->string('issued_from')->toString()) {
            $query->whereDate('issued_at', '>=', $from);
        }
        if ($to = $request->string('issued_to')->toString()) {
            $query->whereDate('issued_at', '<=', $to);
        }
    }

    /**
     * @param  Collection<int, NfeDocument>  $models
     * @return array<string, true>
     */
    private function fullAccessKeysForNfe($models): array
    {
        $summaryKeys = $models->where('is_summary', true)->pluck('access_key')->filter()->unique()->values()->all();
        if ($summaryKeys === []) {
            return [];
        }
        $officeIds = $models->pluck('office_id')->unique()->all();

        return NfeDocument::query()
            ->whereIn('office_id', $officeIds)
            ->whereIn('access_key', $summaryKeys)
            ->where('is_summary', false)
            ->pluck('access_key')
            ->flip()
            ->map(fn () => true)
            ->all();
    }

    /**
     * @param  Collection<int, CteDocument>  $models
     * @return array<string, true>
     */
    private function fullAccessKeysForCte($models): array
    {
        $summaryKeys = $models->where('is_summary', true)->pluck('access_key')->filter()->unique()->values()->all();
        if ($summaryKeys === []) {
            return [];
        }
        $officeIds = $models->pluck('office_id')->unique()->all();

        return CteDocument::query()
            ->whereIn('office_id', $officeIds)
            ->whereIn('access_key', $summaryKeys)
            ->where('is_summary', false)
            ->pluck('access_key')
            ->flip()
            ->map(fn () => true)
            ->all();
    }

    /**
     * Chip da grade: Autorizada · Cancelada · Denegada · Em revisão.
     * Não misturar com completude de XML (campo xml_completeness_label).
     */
    private function nfeFiscalStatusLabel(?string $status, ?string $cStat): string
    {
        $code = $cStat !== null ? trim($cStat) : '';
        if (in_array($code, ['101', '151', '155'], true)) {
            return 'Cancelada';
        }
        if (in_array($code, ['110', '301', '302'], true)) {
            return 'Denegada';
        }
        if (in_array($code, ['100', '150'], true)) {
            return 'Autorizada';
        }

        $normalized = strtoupper(trim((string) $status));
        if (in_array($normalized, ['CANCELLED', 'SUPERSEDED', 'REPLACED', 'CANCELED'], true)) {
            return 'Cancelada';
        }
        if (in_array($normalized, ['DENIED', 'DENEGADA'], true)) {
            return 'Denegada';
        }
        if (in_array($normalized, ['ACTIVE', 'AUTHORIZED', 'SUBSTITUTE', 'JUDICIAL'], true)) {
            return 'Autorizada';
        }

        // Reuso do mapa operacional NFS-e (mesmos grupos)
        return NfseNoteStatus::label($normalized !== '' ? $normalized : 'UNKNOWN');
    }

    /**
     * Texto oficial/detalhe (não substitui o chip da lista).
     */
    private function nfeOfficialStatusLabel(?string $status, ?string $cStat, bool $summaryOnly): ?string
    {
        $code = $cStat !== null ? trim($cStat) : '';
        $map = [
            '100' => 'Autorizada o uso da NF-e',
            '150' => 'Autorizada fora de prazo',
            '101' => 'Cancelamento homologado',
            '151' => 'Cancelamento fora de prazo',
            '155' => 'Cancelamento homologado',
            '110' => 'Uso denegado',
            '301' => 'Uso denegado',
            '302' => 'Uso denegado',
        ];
        if ($code !== '' && isset($map[$code])) {
            $base = $map[$code];

            return $summaryOnly ? $base.' · somente resumo' : $base;
        }

        $chip = $this->nfeFiscalStatusLabel($status, $cStat);
        if ($summaryOnly) {
            return $chip.' · somente resumo';
        }

        return $chip;
    }
}
