<?php

namespace App\Models;

use App\Enums\TaxProxyPowerSource;
use App\Enums\TaxProxyPowerStatus;
use App\Models\Concerns\BelongsToOffice;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'office_id',
    'client_id',
    'office_serpro_authorization_id',
    'environment',
    'author_identity',
    'contributor_cnpj',
    'system_code',
    'service_code',
    'power_code',
    'source',
    'provenance',
    'status',
    'valid_from',
    'valid_to',
    'accepted_at',
    'freshness_checked_at',
    'closed_at',
    'segregation_class',
    'evidence_ref',
    'evidence_sha256',
    'verified_at',
    'last_check_result',
    'metadata',
])]
class TaxProxyPower extends Model
{
    use BelongsToOffice;

    protected function casts(): array
    {
        return [
            'source' => TaxProxyPowerSource::class,
            'status' => TaxProxyPowerStatus::class,
            'valid_from' => 'immutable_datetime',
            'valid_to' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'freshness_checked_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function authorization(): BelongsTo
    {
        return $this->belongsTo(OfficeSerproAuthorization::class, 'office_serpro_authorization_id');
    }

    public function isCurrentlyValid(): bool
    {
        if (! $this->status->isUsable()) {
            return false;
        }

        if ($this->closed_at !== null) {
            return false;
        }

        if ($this->valid_to !== null && $this->valid_to->isPast()) {
            return false;
        }

        if ($this->valid_from !== null && $this->valid_from->isFuture()) {
            return false;
        }

        return true;
    }

    /**
     * Aceite RFB do autorizado (quando aplicável). Sem accepted_at = inelegível para ops reais.
     */
    public function isAcceptedByAuthorizee(): bool
    {
        return $this->accepted_at !== null;
    }

    /**
     * Frescor da evidência de procuração (max age em horas, default 24*7).
     */
    public function isFresh(?int $maxAgeHours = null): bool
    {
        $maxAgeHours ??= (int) config('serpro.proxy_powers.freshness_max_age_hours', 168);
        $checked = $this->freshness_checked_at ?? $this->verified_at;
        if ($checked === null) {
            return false;
        }

        return $checked->greaterThan(now()->subHours(max(1, $maxAgeHours)));
    }

    /**
     * Vigência em D-1 (Eventos): precisa cobrir o dia civil anterior em America/Sao_Paulo.
     * Compara datas civis (Y-m-d) para evitar drift de timezone app UTC vs SP.
     */
    public function coversD1(?CarbonImmutable $reference = null): bool
    {
        $tz = 'America/Sao_Paulo';
        $ref = ($reference ?? CarbonImmutable::now($tz))->timezone($tz);
        $d1Date = $ref->subDay()->toDateString(); // Y-m-d

        if ($this->status !== TaxProxyPowerStatus::Active || $this->closed_at !== null) {
            return false;
        }

        // Datas civis: interpretamos o Y-m-d gravado como data de calendário (sem shift TZ).
        $fromDate = $this->valid_from !== null
            ? CarbonImmutable::parse((string) $this->valid_from)->toDateString()
            : null;
        $toDate = $this->valid_to !== null
            ? CarbonImmutable::parse((string) $this->valid_to)->toDateString()
            : null;

        if ($fromDate !== null && $fromDate > $d1Date) {
            return false;
        }
        if ($toDate !== null && $toDate < $d1Date) {
            return false;
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'office_id' => $this->office_id,
            'client_id' => $this->client_id,
            'author_identity_masked' => $this->mask($this->author_identity),
            'contributor_cnpj_masked' => $this->mask($this->contributor_cnpj),
            'system_code' => $this->system_code,
            'service_code' => $this->service_code,
            'power_code' => $this->power_code,
            'environment' => $this->environment,
            'source' => $this->source->value,
            'provenance' => $this->provenance,
            'status' => $this->status->value,
            'valid_from' => $this->valid_from?->toIso8601String(),
            'valid_to' => $this->valid_to?->toIso8601String(),
            'accepted_at' => $this->accepted_at?->toIso8601String(),
            'freshness_checked_at' => $this->freshness_checked_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'segregation_class' => $this->segregation_class,
            'evidence_ref' => $this->evidence_ref,
            'evidence_sha256' => $this->evidence_sha256,
            'verified_at' => $this->verified_at?->toIso8601String(),
            'last_check_result' => $this->last_check_result,
            'is_currently_valid' => $this->isCurrentlyValid(),
            'is_accepted' => $this->isAcceptedByAuthorizee(),
            'is_fresh' => $this->isFresh(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    private function mask(string $value): string
    {
        $value = strtoupper(preg_replace('/[^0-9A-Za-z]/', '', $value) ?? $value);
        $len = strlen($value);
        if ($len <= 4) {
            return str_repeat('*', $len);
        }

        return substr($value, 0, 2).str_repeat('*', max(0, $len - 6)).substr($value, -4);
    }
}
