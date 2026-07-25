<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Policies\ClientCredentialPolicy;
use App\Services\Audit\AuditLogger;
use App\Services\Certificates\CredentialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

class ClientCredentialController extends Controller
{
    public function show(Client $client, CredentialService $credentials): JsonResponse
    {
        $this->authorize('view', $client);
        $this->assertAdmin($client);

        $active = $credentials->activeFor($client);

        return response()->json([
            'data' => $active?->toPublicArray(),
        ]);
    }

    public function store(
        Request $request,
        Client $client,
        CredentialService $credentials,
        AuditLogger $audit,
    ): JsonResponse {
        $this->authorize('update', $client);
        $this->assertAdmin($client);

        $data = $request->validate([
            'pfx' => ['required', 'file', 'max:5120'],
            'password' => ['required', 'string'],
        ]);

        try {
            $binary = file_get_contents($data['pfx']->getRealPath());
            if ($binary === false) {
                throw new RuntimeException('Falha ao ler arquivo PFX.');
            }

            $credential = $credentials->activate($client, $binary, $data['password']);
        } catch (RuntimeException $e) {
            // Nunca passar password/PFX ao pipeline de auditoria — só mensagem sanitizada.
            $audit->record('credential.activate', 'FAILED', $client, [
                'message' => $e->getMessage() ?: 'Falha ao ativar certificado.',
            ]);

            return response()->json([
                'message' => $e->getMessage() ?: 'Falha ao ativar certificado.',
            ], 422);
        } catch (Throwable $e) {
            report($e);
            $audit->record('credential.activate', 'FAILED', $client, [
                'message' => 'Falha ao ativar certificado.',
            ]);

            return response()->json([
                'message' => 'Falha ao ativar certificado.',
            ], 422);
        }

        $audit->record('credential.activate', 'SUCCESS', $credential, [
            'client_id' => $client->id,
            'fingerprint_sha256' => $credential->fingerprint_sha256,
            'holder_cnpj' => $credential->holder_cnpj,
            'valid_to' => $credential->valid_to?->toIso8601String(),
        ]);

        return response()->json([
            'data' => $credential->toPublicArray(),
        ], 201);
    }

    private function assertAdmin(Client $client): void
    {
        $policy = app(ClientCredentialPolicy::class);
        if (! $policy->manage(auth()->user(), $client)) {
            abort(403, 'Apenas administradores podem gerenciar certificados.');
        }
    }
}
