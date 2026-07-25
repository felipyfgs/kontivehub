<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\RegistrationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Clients\StoreEstablishmentRequest;
use App\Http\Requests\Clients\UpdateEstablishmentRequest;
use App\Models\Client;
use App\Models\Establishment;
use App\Services\Audit\AuditLogger;
use App\Services\Clients\CaptureEligibilityService;
use App\Support\CurrentOffice;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EstablishmentController extends Controller
{
    /**
     * Criação de estabelecimento adicional desabilitada no produto:
     * 1 cliente = 1 CNPJ. Filiais = novo cliente.
     */
    public function store(
        StoreEstablishmentRequest $request,
        Client $client,
        CurrentOffice $currentOffice,
        AuditLogger $audit,
    ): JsonResponse {
        $this->authorize('create', Establishment::class);
        $this->authorize('view', $client);

        return response()->json([
            'message' => 'Cada cliente possui um único estabelecimento. Cadastre a filial como um novo cliente.',
            'errors' => [
                'cnpj' => ['Use “Novo cliente” com o CNPJ completo da filial. Não se adicionam filiais sob o perfil da matriz.'],
            ],
        ], 422);
    }

    public function update(
        UpdateEstablishmentRequest $request,
        Establishment $establishment,
        AuditLogger $audit,
        CaptureEligibilityService $eligibility,
    ): JsonResponse {
        $this->authorize('update', $establishment);

        $data = $request->validated();

        $wantsCaptureEnable = array_key_exists('capture_enabled', $data)
            && $data['capture_enabled'] === true
            && ! $establishment->capture_enabled;

        $status = $establishment->registration_status;
        if (isset($data['registration_status'])) {
            $status = $this->resolveStatus($data['registration_status']);
        }

        $knownNonActive = $status !== null
            && ! $status->isActive()
            && $status !== RegistrationStatus::Unknown;

        if ($wantsCaptureEnable && $knownNonActive) {
            $reason = trim((string) ($data['capture_enable_reason'] ?? ''));
            if ($reason === '') {
                return response()->json([
                    'message' => 'Informe o motivo para habilitar captura com situação cadastral não ativa.',
                    'errors' => ['capture_enable_reason' => ['Motivo obrigatório para habilitação excepcional.']],
                ], 422);
            }
        }

        try {
            $establishment = DB::transaction(function () use ($data, $establishment, $status): Establishment {
                $locked = Establishment::query()->whereKey($establishment->id)->lockForUpdate()->firstOrFail();

                if (array_key_exists('is_matrix', $data) && $data['is_matrix'] && ! $locked->is_matrix) {
                    // Cliente-filial (matrix_client_id ≠ null) não pode ser marcado como matriz.
                    $client = Client::query()
                        ->whereKey($locked->client_id)
                        ->lockForUpdate()
                        ->first();
                    if ($client !== null && $client->matrix_client_id !== null) {
                        throw ValidationException::withMessages([
                            'is_matrix' => ['Cliente vinculado como filial não pode ter estabelecimento matriz. Desvincule a matriz ou cadastre a matriz no cliente raiz.'],
                        ]);
                    }

                    $hasOtherMatrix = Establishment::query()
                        ->where('client_id', $locked->client_id)
                        ->where('is_matrix', true)
                        ->where('id', '!=', $locked->id)
                        ->lockForUpdate()
                        ->exists();
                    if ($hasOtherMatrix) {
                        throw ValidationException::withMessages([
                            'is_matrix' => ['Já existe uma matriz para este cliente.'],
                        ]);
                    }
                }

                $fill = collect($data)->except(['address', 'capture_enable_reason'])->all();
                if (isset($data['registration_status'])) {
                    $fill['registration_status'] = $status;
                }
                if (isset($data['address']) && is_array($data['address'])) {
                    $address = $data['address'];
                    $fill['address_postal_code'] = $address['postal_code'] ?? $locked->address_postal_code;
                    $fill['address_street_type'] = $address['street_type'] ?? $locked->address_street_type;
                    $fill['address_street'] = $address['street'] ?? $locked->address_street;
                    $fill['address_number'] = $address['number'] ?? $locked->address_number;
                    $fill['address_complement'] = $address['complement'] ?? $locked->address_complement;
                    $fill['address_district'] = $address['district'] ?? $locked->address_district;
                    $fill['address_city'] = $address['city'] ?? $locked->address_city;
                    $fill['address_city_ibge_code'] = $address['city_ibge_code'] ?? $locked->address_city_ibge_code;
                    $fill['address_state'] = $address['state'] ?? $locked->address_state;
                    $fill['address_country'] = $address['country'] ?? $locked->address_country;
                }

                $locked->fill($fill);
                $locked->save();

                return $locked->fresh() ?? $locked;
            });
        } catch (ValidationException $e) {
            throw $e;
        }

        $changed = array_keys($data);
        $auditPayload = ['fields' => $changed];
        if ($wantsCaptureEnable) {
            $reason = trim((string) ($data['capture_enable_reason'] ?? ''));
            $auditPayload['capture_enable_reason_present'] = $reason !== '';
            // Texto do motivo (já validado max 1000) para trilha auditável de habilitação excepcional.
            if ($reason !== '') {
                $auditPayload['capture_enable_reason'] = $reason;
            }
            $auditPayload['registration_status'] = $status?->value;
            $audit->record('establishment.capture_enable', 'SUCCESS', $establishment, $auditPayload);
        } else {
            $audit->record('establishment.update', 'SUCCESS', $establishment, $auditPayload);
        }

        $payload = $this->serialize($establishment);
        $payload['capture_eligibility'] = $eligibility->evaluate($establishment);

        return response()->json(['data' => $payload]);
    }

    private function resolveStatus(mixed $raw): RegistrationStatus
    {
        if ($raw === null || $raw === '') {
            return RegistrationStatus::Unknown;
        }
        $value = (string) $raw;
        try {
            return RegistrationStatus::from($value);
        } catch (\ValueError) {
            return RegistrationStatus::fromExternal($value);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(Establishment $est): array
    {
        return [
            'id' => $est->id,
            'office_id' => $est->office_id,
            'client_id' => $est->client_id,
            'cnpj' => $est->cnpj,
            'trade_name' => $est->trade_name,
            'is_matrix' => $est->is_matrix,
            'is_active' => $est->is_active,
            'registration_status' => $est->registration_status?->value ?? $est->registration_status,
            'registration_status_at' => $est->registration_status_at?->toDateString(),
            'registration_status_reason' => $est->registration_status_reason,
            'activity_started_at' => $est->activity_started_at?->toDateString(),
            'main_cnae_code' => $est->main_cnae_code,
            'main_cnae_name' => $est->main_cnae_name,
            'secondary_cnaes' => is_array($est->secondary_cnaes) ? $est->secondary_cnaes : [],
            'state_registrations' => is_array($est->state_registrations) ? $est->state_registrations : [],
            'shareholders' => is_array($est->shareholders) ? $est->shareholders : [],
            'address' => $est->addressPayload(),
            'public_email' => $est->public_email,
            'public_phone' => $est->public_phone,
            'public_phone_secondary' => $est->public_phone_secondary,
            'public_fax' => $est->public_fax,
            'special_situation' => $est->special_situation,
            'special_situation_at' => $est->special_situation_at?->toDateString(),
            'simples_optant' => $est->simples_optant,
            'mei_optant' => $est->mei_optant,
            'capture_enabled' => $est->capture_enabled,
            'registration_source' => $est->registration_source?->value ?? $est->registration_source,
            'registration_refreshed_at' => $est->registration_refreshed_at?->toIso8601String(),
            'created_at' => $est->created_at?->toIso8601String(),
            'updated_at' => $est->updated_at?->toIso8601String(),
        ];
    }
}
