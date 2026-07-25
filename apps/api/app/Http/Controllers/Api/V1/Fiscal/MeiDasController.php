<?php

namespace App\Http\Controllers\Api\V1\Fiscal;

use App\Enums\FiscalMutationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Fiscal\Mei\GenerateMeiDasPreflightRequest;
use App\Http\Requests\Fiscal\Mei\GenerateMeiDasRequest;
use App\Models\Client;
use App\Models\FiscalMutationOperation;
use App\Models\MeiAutomationAttempt;
use App\Models\User;
use App\Services\Fiscal\Mutations\FiscalMutationException;
use App\Services\Fiscal\Mutations\FiscalMutationService;
use App\Support\CurrentOffice;
use Illuminate\Http\JsonResponse;

final class MeiDasController extends Controller
{
    public function __construct(
        private readonly CurrentOffice $currentOffice,
        private readonly FiscalMutationService $mutations,
    ) {}

    public function preflight(GenerateMeiDasPreflightRequest $request): JsonResponse
    {
        $office = $this->currentOffice->office();
        $client = $this->client((int) $office->id, (int) $request->validated('client_id'));
        if ($client === null) {
            return $this->clientNotFound();
        }
        $actor = $request->user();
        if (! $actor instanceof User) {
            abort(401);
        }

        $competencies = $this->competencies($request->validated('competencies'));
        $dueDate = $this->dueDate($request->validated('due_date'));
        $result = $this->mutations->preflight(
            office: $office,
            client: $client,
            user: $actor,
            solutionCode: 'INTEGRA_MEI',
            serviceCode: 'PGMEI',
            operationCode: 'GERAR_DAS',
            competencePeriodKey: $this->competenceKey($competencies),
            idempotencyKey: (string) $request->validated('idempotency_key'),
            requestPayload: [
                'competencies' => $competencies,
                'due_date' => $dueDate,
                'output_format' => (string) $request->validated('output_format'),
            ],
            module: 'simples_mei',
        );

        return response()->json(['data' => $result->toArray()], $result->eligible ? 200 : 422);
    }

    public function store(GenerateMeiDasRequest $request): JsonResponse
    {
        $office = $this->currentOffice->office();
        $client = $this->client((int) $office->id, (int) $request->validated('client_id'));
        if ($client === null) {
            return $this->clientNotFound();
        }
        $actor = $request->user();
        if (! $actor instanceof User) {
            abort(401);
        }
        $competencies = $this->competencies($request->validated('competencies'));
        $dueDate = $this->dueDate($request->validated('due_date'));

        try {
            $operation = $this->mutations->execute(
                office: $office,
                client: $client,
                user: $actor,
                solutionCode: 'INTEGRA_MEI',
                serviceCode: 'PGMEI',
                operationCode: 'GERAR_DAS',
                confirmationPhrase: (string) $request->validated('confirmation_phrase'),
                confirmed: true,
                competencePeriodKey: $this->competenceKey($competencies),
                idempotencyKey: (string) $request->validated('idempotency_key'),
                preflightToken: (string) $request->validated('preflight_token'),
                requestPayload: [
                    'competencies' => $competencies,
                    'due_date' => $dueDate,
                    'output_format' => (string) $request->validated('output_format'),
                ],
                module: 'simples_mei',
            );
        } catch (FiscalMutationException $error) {
            return response()->json($error->toArray(), $error->httpStatus());
        }

        $attempt = MeiAutomationAttempt::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->where('fiscal_mutation_operation_id', $operation->id)
            ->latest('id')
            ->first();

        return response()->json([
            'data' => [
                'mutation' => $this->publicMutation($operation),
                'attempt' => $attempt?->toPublicArray(),
            ],
        ], $operation->status === FiscalMutationStatus::Sent ? 202 : 201);
    }

    /**
     * @return list<string>
     */
    private function competencies(mixed $value): array
    {
        $competencies = is_array($value) ? array_values(array_map('strval', $value)) : [];
        sort($competencies, SORT_STRING);

        return $competencies;
    }

    /** @param list<string> $competencies */
    private function competenceKey(array $competencies): string
    {
        if (count($competencies) === 1) {
            return $competencies[0];
        }

        return 'MULTI:'.substr(hash('sha256', implode('|', $competencies)), 0, 12);
    }

    private function dueDate(mixed $value): string
    {
        return is_string($value) && $value !== ''
            ? $value
            : now('America/Sao_Paulo')->toDateString();
    }

    /** @return array<string, mixed> */
    private function publicMutation(FiscalMutationOperation $operation): array
    {
        $data = $operation->toPublicArray();
        unset($data['office_id']);

        return $data;
    }

    private function client(int $officeId, int $clientId): ?Client
    {
        return Client::query()
            ->withoutGlobalScopes()
            ->where('office_id', $officeId)
            ->whereKey($clientId)
            ->first();
    }

    private function clientNotFound(): JsonResponse
    {
        return response()->json([
            'message' => 'Cliente não encontrado no escritório atual.',
            'code' => 'CLIENT_NOT_FOUND',
        ], 404);
    }
}
