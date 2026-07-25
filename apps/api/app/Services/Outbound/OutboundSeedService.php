<?php

namespace App\Services\Outbound;

use App\Contracts\SecureObjectStore;
use App\Enums\OutboundCaptureMode;
use App\Enums\OutboundNumberStatus;
use App\Enums\OutboundProfileStatus;
use App\Enums\OutboundSeriesStatus;
use App\Models\Establishment;
use App\Models\OutboundCaptureProfile;
use App\Models\OutboundNumberState;
use App\Models\OutboundSeriesCursor;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class OutboundSeedService
{
    public function __construct(
        private readonly OutboundSeedValidator $validator,
        private readonly SecureObjectStore $store,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Cadastra ou substitui semente; não clona itens/tributos para operação fiscal.
     *
     * @return array{profile: OutboundCaptureProfile, series: OutboundSeriesCursor}
     */
    public function registerSeed(
        Establishment $establishment,
        string $xml,
        string $environment,
        int $userId,
        ?OutboundCaptureMode $mode = null,
    ): array {
        $validated = $this->validator->validate($xml, $establishment, $environment);

        if ($validated['environment'] !== $environment) {
            throw new RuntimeException('Ambiente da semente diverge do solicitado.');
        }

        return DB::transaction(function () use ($establishment, $xml, $environment, $userId, $mode, $validated) {
            $profile = OutboundCaptureProfile::query()->firstOrCreate(
                [
                    'establishment_id' => $establishment->id,
                    'environment' => $environment,
                    'model' => $validated['model']->value,
                ],
                [
                    'office_id' => $establishment->office_id,
                    'client_id' => $establishment->client_id,
                    'uf' => 'MA',
                    'mode' => $mode ?? OutboundCaptureMode::Assisted,
                    'status' => OutboundProfileStatus::Draft,
                ]
            );

            $sha = hash('sha256', $xml);
            // AAD canônico: office_id + sha256 (export/download usam o mesmo par).
            $objectId = $this->store->put($xml, [
                'office_id' => $establishment->office_id,
                'sha256' => $sha,
            ]);

            $series = OutboundSeriesCursor::query()->updateOrCreate(
                [
                    'establishment_id' => $establishment->id,
                    'environment' => $environment,
                    'model' => $validated['model']->value,
                    'series' => $validated['series'],
                ],
                [
                    'office_id' => $establishment->office_id,
                    'outbound_capture_profile_id' => $profile->id,
                    'seed_nnf' => $validated['nnf'],
                    'discovery_position' => $validated['nnf'] + 1,
                    'highest_confirmed_nnf' => $validated['nnf'],
                    'status' => OutboundSeriesStatus::SeedReady,
                    'tp_emis' => $validated['tp_emis'],
                    'seed_access_key' => $validated['access_key'],
                    'seed_vault_object_id' => $objectId,
                    'seed_sha256' => $sha,
                    'seed_issued_at' => $validated['issued_at'],
                ]
            );

            // Estado do número da semente — já capturado via XML fornecido
            OutboundNumberState::query()->updateOrCreate(
                [
                    'outbound_capture_profile_id' => $profile->id,
                    'series' => $validated['series'],
                    'nnf' => $validated['nnf'],
                ],
                [
                    'office_id' => $establishment->office_id,
                    'outbound_series_cursor_id' => $series->id,
                    'status' => OutboundNumberStatus::XmlCaptured,
                    'candidate_access_key' => $validated['access_key'],
                    'discovered_access_key' => $validated['access_key'],
                    'last_cstat' => $validated['cstat'],
                    'protocol' => $validated['protocol'],
                    'attempts' => 0,
                    'key_discovered_at' => now(),
                    'xml_captured_at' => now(),
                ]
            );

            if ($profile->status === OutboundProfileStatus::Draft) {
                $profile->forceFill(['status' => OutboundProfileStatus::SeedReady])->save();
            }

            $this->audit->record(
                'outbound.seed.registered',
                'SUCCESS',
                $series,
                [
                    'profile_id' => $profile->id,
                    'series' => $validated['series'],
                    'seed_nnf' => $validated['nnf'],
                    'model' => $validated['model']->value,
                    'access_key' => $validated['access_key'],
                    'environment' => $environment,
                    // sem XML bruto
                ],
                $userId,
                $establishment->office_id,
            );

            return ['profile' => $profile->fresh(), 'series' => $series->fresh()];
        });
    }
}
