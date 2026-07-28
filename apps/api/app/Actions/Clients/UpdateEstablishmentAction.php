<?php

namespace App\Actions\Clients;

use App\DTO\Clients\EstablishmentUpdateData;
use App\DTO\Clients\EstablishmentUpdateResult;
use App\Enums\RegistrationStatus;
use App\Exceptions\EstablishmentApiException;
use App\Models\Client;
use App\Models\Establishment;
use App\Services\Audit\AuditLogger;
use App\Services\Clients\CaptureEligibilityService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class UpdateEstablishmentAction
{
    public function __construct(
        private AuditLogger $audit,
        private CaptureEligibilityService $eligibility,
    ) {}

    public function __invoke(
        Establishment $establishment,
        EstablishmentUpdateData $data,
    ): EstablishmentUpdateResult {
        $outcome = DB::transaction(function () use ($establishment, $data): array {
            Client::query()
                ->whereKey($establishment->client_id)
                ->lockForUpdate()
                ->firstOrFail();

            $locked = Establishment::query()
                ->whereKey($establishment->id)
                ->lockForUpdate()
                ->firstOrFail();

            $status = $this->registrationStatus($locked, $data->attributes);
            $wantsCaptureEnable = array_key_exists('capture_enabled', $data->attributes)
                && $data->attributes['capture_enabled'] === true
                && ! $locked->capture_enabled;
            $captureEnableReason = trim((string) ($data->attributes['capture_enable_reason'] ?? ''));

            if ($wantsCaptureEnable
                && $status !== null
                && ! $status->isActive()
                && $status !== RegistrationStatus::Unknown
                && $captureEnableReason === '') {
                throw EstablishmentApiException::captureEnableReasonRequired();
            }

            if (array_key_exists('is_headquarters', $data->attributes)
                && $data->attributes['is_headquarters']
                && ! $locked->is_headquarters
                && Establishment::query()
                    ->where('client_id', $locked->client_id)
                    ->where('is_headquarters', true)
                    ->where('id', '!=', $locked->id)
                    ->exists()) {
                throw ValidationException::withMessages([
                    'is_headquarters' => ['Já existe uma matriz para este cliente.'],
                ]);
            }

            $fill = Arr::except(
                $data->attributes,
                ['address', 'capture_enable_reason', 'registration_status'],
            );
            if (isset($data->attributes['registration_status'])) {
                $fill['registration_status'] = $status;
            }
            if (isset($data->attributes['address']) && is_array($data->attributes['address'])) {
                $fill += $this->addressAttributes($locked, $data->attributes['address']);
            }

            $locked->fill($fill);
            $locked->save();

            return [
                'establishment' => $locked->fresh() ?? $locked,
                'wants_capture_enable' => $wantsCaptureEnable,
                'capture_enable_reason_present' => $captureEnableReason !== '',
                'registration_status' => $status?->value,
            ];
        });

        /** @var Establishment $updated */
        $updated = $outcome['establishment'];
        $auditPayload = ['fields' => array_keys($data->attributes)];

        if ($outcome['wants_capture_enable']) {
            $auditPayload['capture_enable_reason_present'] = $outcome['capture_enable_reason_present'];
            $auditPayload['registration_status'] = $outcome['registration_status'];
            $this->audit->record(
                'establishment.capture_enable',
                'SUCCESS',
                $updated,
                $auditPayload,
            );
        } else {
            $this->audit->record('establishment.update', 'SUCCESS', $updated, $auditPayload);
        }

        return new EstablishmentUpdateResult(
            establishment: $updated,
            captureEligibility: $this->eligibility->evaluate($updated),
        );
    }

    /** @param array<string, mixed> $attributes */
    private function registrationStatus(
        Establishment $establishment,
        array $attributes,
    ): ?RegistrationStatus {
        if (! isset($attributes['registration_status'])) {
            return $establishment->registration_status;
        }

        return RegistrationStatus::from((string) $attributes['registration_status']);
    }

    /**
     * @param  array<string, mixed>  $address
     * @return array<string, mixed>
     */
    private function addressAttributes(Establishment $establishment, array $address): array
    {
        return [
            'address_postal_code' => $address['postal_code'] ?? $establishment->address_postal_code,
            'address_street_type' => $address['street_type'] ?? $establishment->address_street_type,
            'address_street' => $address['street'] ?? $establishment->address_street,
            'address_number' => $address['number'] ?? $establishment->address_number,
            'address_complement' => $address['complement'] ?? $establishment->address_complement,
            'address_district' => $address['district'] ?? $establishment->address_district,
            'address_city' => $address['city'] ?? $establishment->address_city,
            'address_city_ibge_code' => $address['city_ibge_code']
                ?? $establishment->address_city_ibge_code,
            'address_state' => $address['state'] ?? $establishment->address_state,
            'address_country' => $address['country'] ?? $establishment->address_country,
        ];
    }
}
