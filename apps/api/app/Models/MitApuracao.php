<?php

namespace App\Models;

use App\Enums\DctfwebTransmissionStatus;
use App\Enums\FiscalCoverage;
use App\Enums\FiscalSituation;
use App\Enums\MitEncerramentoStatus;
use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Apuração/encerramento MIT — estado independente da transmissão DCTFWeb.
 */
#[Fillable([
    'office_id',
    'client_id',
    'competence_id',
    'period_key',
    'encerramento_status',
    'situacao_status',
    'dctfweb_transmission_status',
    'situation',
    'coverage',
    'encerrado_at',
    'observed_at',
    'current_snapshot_id',
    'metadata',
])]
class MitApuracao extends Model
{
    use BelongsToOffice;

    protected $table = 'mit_apuracoes';

    protected function casts(): array
    {
        return [
            'encerramento_status' => MitEncerramentoStatus::class,
            'dctfweb_transmission_status' => DctfwebTransmissionStatus::class,
            'situation' => FiscalSituation::class,
            'coverage' => FiscalCoverage::class,
            'encerrado_at' => 'immutable_datetime',
            'observed_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function competence(): BelongsTo
    {
        return $this->belongsTo(FiscalCompetence::class, 'competence_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        $metadata = is_array($this->metadata) ? $this->metadata : [];
        $listaApuracao = is_array($metadata['lista_apuracoes_317'] ?? null)
            ? $metadata['lista_apuracoes_317']
            : null;

        return [
            'id' => $this->id,
            'office_id' => $this->office_id,
            'client_id' => $this->client_id,
            'competence_id' => $this->competence_id,
            'period_key' => $this->period_key,
            'encerramento_status' => $this->encerramento_status?->value,
            'situacao_status' => $this->situacao_status,
            'dctfweb_transmission_status' => $this->dctfweb_transmission_status?->value,
            'situation' => $this->situation?->value,
            'coverage' => $this->coverage?->value,
            'encerrado_at' => $this->encerrado_at?->toIso8601String(),
            'observed_at' => $this->observed_at?->toIso8601String(),
            'current_snapshot_id' => $this->current_snapshot_id,
            // Dados normalizados da lista 317; nunca metadata bruta, XML ou cofre.
            'lista_apuracoes_317' => $listaApuracao,
            /** Painel: etapas correlacionadas porém distintas. */
            'stages' => [
                'mit_encerramento' => $this->encerramento_status?->value,
                'dctfweb_transmissao' => $this->dctfweb_transmission_status?->value,
            ],
        ];
    }
}
