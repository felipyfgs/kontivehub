<?php

namespace App\Http\Controllers\Api\V1\Fiscal;

use App\Enums\OfficeRole;
use App\Http\Controllers\Controller;
use App\Jobs\Fiscal\RefreshRegistrationLinksJob;
use App\Models\Client;
use App\Models\FiscalRegistrationLink;
use App\Support\CurrentOffice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * APIs tenant-scoped de Cadastro/Vínculos (office da sessão).
 */
final class RegistrationLinkController extends Controller
{
    public function index(Request $request, CurrentOffice $currentOffice): JsonResponse
    {
        $office = $currentOffice->office();
        abort_if($office === null, 403);

        // office_id do client é ignorado (EnsureOfficeContext)
        $query = FiscalRegistrationLink::query()
            ->where('office_id', $office->id)
            ->orderByDesc('refreshed_at')
            ->orderByDesc('id');

        if ($request->filled('client_id')) {
            $query->where('client_id', (int) $request->query('client_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }
        $search = trim((string) $request->query('q', ''));
        if ($search !== '') {
            $search = mb_substr($search, 0, 120);
            $like = '%'.addcslashes($search, '%_\\').'%';
            $normalizedDigits = preg_replace('/\D+/', '', $search) ?: '';
            $digits = strlen($normalizedDigits) >= 8 ? $normalizedDigits : null;
            $query->where(function (Builder $filter) use ($search, $like, $digits, $office): void {
                $filter->where('link_key', 'like', $like)
                    ->orWhere('source_provenance', 'like', $like)
                    ->when(ctype_digit($search), fn (Builder $q) => $q->orWhere('client_id', (int) $search))
                    ->orWhereHas('client', function (Builder $client) use ($like, $digits, $office): void {
                        $client->where('office_id', $office->id)
                            ->where(function (Builder $identity) use ($like, $digits): void {
                                $identity->where('legal_name', 'like', $like)
                                    ->orWhere('display_name', 'like', $like);
                                if ($digits !== null) {
                                    $identity->orWhere('root_cnpj', 'like', '%'.$digits.'%');
                                }
                            });
                    });
            });
        }

        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));
        $page = $query->paginate($perPage);

        return response()->json([
            'data' => array_map(
                static fn (FiscalRegistrationLink $row) => $row->toPublicArray(),
                $page->items(),
            ),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    public function showForClient(int $clientId, CurrentOffice $currentOffice): JsonResponse
    {
        $office = $currentOffice->office();
        abort_if($office === null, 403);

        $client = Client::query()
            ->where('office_id', $office->id)
            ->whereKey($clientId)
            ->firstOrFail();

        $rows = FiscalRegistrationLink::query()
            ->where('office_id', $office->id)
            ->where('client_id', $client->id)
            ->orderByDesc('refreshed_at')
            ->get();

        return response()->json([
            'data' => [
                'client_id' => $client->id,
                'links' => $rows->map(static fn (FiscalRegistrationLink $r) => $r->toPublicArray())->values(),
            ],
        ]);
    }

    public function refresh(int $clientId, CurrentOffice $currentOffice): JsonResponse
    {
        $office = $currentOffice->office();
        abort_if($office === null, 403);
        $role = $currentOffice->role();
        if ($role === null || ! in_array($role, [OfficeRole::Admin, OfficeRole::Operator], true)) {
            abort(403);
        }

        $client = Client::query()
            ->where('office_id', $office->id)
            ->whereKey($clientId)
            ->firstOrFail();

        $job = RefreshRegistrationLinksJob::dispatchIfAllowed(
            (int) $office->id,
            (int) $client->id,
            bin2hex(random_bytes(8)),
        );

        if ($job === null) {
            return response()->json([
                'message' => 'Capability registrations desabilitada ou kill switch ativo.',
                'data' => ['queued' => false, 'client_id' => $client->id],
            ], 423);
        }

        return response()->json([
            'data' => [
                'queued' => true,
                'client_id' => $client->id,
            ],
        ], 202);
    }
}
