<?php

namespace App\Services\Outbound;

use App\Enums\OutboundNumberStatus;
use App\Models\DfeDocument;
use App\Models\MaOutboundRetrievalRequest;
use App\Models\OutboundCaptureProfile;
use App\Models\OutboundNumberState;
use Illuminate\Support\Facades\Log;

/**
 * Seleção determinística de fonte para XML de saída:
 * vault/import/autXML → SVRS pontual → fallback assistido.
 *
 * @see design D2 add-resilient-svrs-nfe55-outbound-xml-retrieval
 */
final class OutboundXmlRecoveryRouter
{
    public const SOURCE_VAULT = 'VAULT';

    public const SOURCE_OTHER_INGESTION = 'OTHER_INGESTION';

    public const SOURCE_SVRS = 'SVRS_PORTAL';

    public const SOURCE_ASSISTED = 'ASSISTED_FALLBACK';

    public function __construct(
        private readonly SvrsNfceConfig $nfceConfig,
        private readonly SvrsNfe55Config $nfe55Config,
        private readonly SvrsPortalEgressConfig $egressConfig,
    ) {}

    /**
     * @return array{
     *   source: string,
     *   reason: string,
     *   may_call_svrs: bool,
     *   already_has_xml: bool
     * }
     */
    public function route(OutboundNumberState $number, OutboundCaptureProfile $profile): array
    {
        $key = strtoupper(trim((string) ($number->discovered_access_key ?: $number->candidate_access_key)));
        if ($key === '' || strlen($key) !== 44) {
            return [
                'source' => self::SOURCE_ASSISTED,
                'reason' => 'chave_ausente_ou_invalida',
                'may_call_svrs' => false,
                'already_has_xml' => false,
            ];
        }

        if (in_array($number->status, [OutboundNumberStatus::XmlCaptured, OutboundNumberStatus::Complete], true)
            && $number->dfe_document_id) {
            return [
                'source' => self::SOURCE_VAULT,
                'reason' => 'ja_capturado',
                'may_call_svrs' => false,
                'already_has_xml' => true,
            ];
        }

        $existing = DfeDocument::query()
            ->where('office_id', $number->office_id)
            ->where('access_key', $key)
            ->first();

        if ($existing !== null) {
            // Satisfaz prazo e cancela SVRS se ainda houver recovery aberta
            try {
                app(OutboundDeadlineSatisfactionService::class)->markCapturedBySource(
                    (int) $number->office_id,
                    $key,
                    'VAULT_OR_CATALOG',
                    $existing->sha256,
                    $existing->id,
                );
            } catch (\Throwable) {
            }

            return [
                'source' => self::SOURCE_OTHER_INGESTION,
                'reason' => 'vault_ou_catalogo',
                'may_call_svrs' => false,
                'already_has_xml' => true,
            ];
        }

        // Acomodação: se recovery ainda na janela, não chama SVRS
        $pending = MaOutboundRetrievalRequest::query()
            ->where('office_id', $number->office_id)
            ->where('access_key', $key)
            ->whereNotNull('accommodation_until')
            ->where('accommodation_until', '>', now())
            ->exists();
        if ($pending) {
            return [
                'source' => self::SOURCE_ASSISTED,
                'reason' => 'acomodacao_fontes_preferenciais',
                'may_call_svrs' => false,
                'already_has_xml' => false,
            ];
        }

        $model = $profile->model?->value ?? (string) $profile->model;
        $channelOn = $model === '65'
            ? $this->nfceConfig->retrievalEnabled()
            : ($model === '55' ? $this->nfe55Config->retrievalEnabled() : false);

        if (! $channelOn) {
            return [
                'source' => self::SOURCE_ASSISTED,
                'reason' => 'canal_svrs_desligado',
                'may_call_svrs' => false,
                'already_has_xml' => false,
            ];
        }

        // SVRS só para uma chave conhecida vinculada — nunca por período/série
        Log::info('outbound.xml_recovery.route', [
            'office_id' => $number->office_id,
            'model' => $model,
            'source' => self::SOURCE_SVRS,
            'key_mask' => substr($key, 0, 6).'...'.substr($key, -4),
            'cohort' => $this->egressConfig->cohortId(),
        ]);

        return [
            'source' => self::SOURCE_SVRS,
            'reason' => 'lacuna_com_chave',
            'may_call_svrs' => true,
            'already_has_xml' => false,
        ];
    }
}
