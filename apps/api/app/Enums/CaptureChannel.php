<?php

namespace App\Enums;

/**
 * Canais de captura DF-e reconhecidos pelo domínio.
 * MDF-e permanece apenas para hidratar valores legados e nunca é operacional.
 */
enum CaptureChannel: string
{
    case NfseAdn = 'NFSE_ADN';
    case NfeDistDfe = 'NFE_DISTDFE';
    case CteDistDfe = 'CTE_DISTDFE';
    case MdfeDistDfe = 'MDFE_DISTDFE';
    /** Captura de saídas MA (nNF — nunca last_nsu). */
    case MaOutbound = 'MA_OUTBOUND';
    /** Escritório como terceiro em autXML NF-e (cursor central por CNPJ-base). */
    case NfeAutXmlDistDfe = 'NFE_AUTXML_DISTDFE';
    /** Escritório como terceiro em autXML CT-e (cursor central por CNPJ-base). */
    case CteAutXmlDistDfe = 'CTE_AUTXML_DISTDFE';
    /** Import manual de XML/ZIP (sem NSU). */
    case ImportXml = 'IMPORT_XML';
    /** Entrega autenticada do emissor (token de integração). */
    case EmitterPush = 'EMITTER_PUSH';

    public function label(): string
    {
        return match ($this) {
            self::NfseAdn => 'NFS-e ADN',
            self::NfeDistDfe => 'NF-e DistDFe',
            self::CteDistDfe => 'CT-e DistDFe',
            self::MdfeDistDfe => 'MDF-e DistDFe',
            self::MaOutbound => 'Saídas MA (NF-e/NFC-e)',
            self::NfeAutXmlDistDfe => 'NF-e autXML (escritório)',
            self::CteAutXmlDistDfe => 'CT-e autXML (escritório)',
            self::ImportXml => 'Import XML/ZIP',
            self::EmitterPush => 'Entrega do emissor',
        };
    }

    public function source(): string
    {
        return match ($this) {
            self::NfseAdn => 'ADN',
            self::MaOutbound => 'SEFAZ_MA',
            self::NfeAutXmlDistDfe, self::CteAutXmlDistDfe => 'SEFAZ_AUTXML',
            self::ImportXml => 'IMPORT',
            self::EmitterPush => 'EMITTER_PUSH',
            default => 'SEFAZ',
        };
    }

    public function documentKind(): ?DocumentKind
    {
        return match ($this) {
            self::NfseAdn => DocumentKind::Nfse,
            self::NfeDistDfe => DocumentKind::Nfe,
            self::CteDistDfe, self::CteAutXmlDistDfe => DocumentKind::Cte,
            self::MdfeDistDfe => DocumentKind::Mdfe,
            self::MaOutbound => null, // 55 e 65 no mesmo canal
            self::NfeAutXmlDistDfe => DocumentKind::Nfe,
            self::ImportXml, self::EmitterPush => null,
        };
    }

    public function featureFlagKey(): string
    {
        return match ($this) {
            self::NfseAdn => 'adn.enabled',
            self::NfeDistDfe => 'sefaz.distdfe_enabled',
            self::CteDistDfe => 'sefaz.cte_enabled',
            self::MdfeDistDfe => 'sefaz.mdfe_enabled',
            self::MaOutbound => 'sefaz.ma_outbound.enabled',
            self::NfeAutXmlDistDfe => 'sefaz.autxml.enabled',
            self::CteAutXmlDistDfe => 'sefaz.cte_autxml.enabled',
            self::ImportXml => 'import.async_batches_enabled',
            self::EmitterPush => 'sefaz.cte_emitter_push.enabled',
        };
    }

    public function isEnabled(): bool
    {
        if ($this === self::MdfeDistDfe) {
            return false;
        }

        if ($this === self::NfseAdn || $this === self::ImportXml) {
            return true;
        }

        if ($this === self::NfeAutXmlDistDfe) {
            if (config('sefaz.autxml.kill_switch', false)) {
                return false;
            }

            return (bool) config('sefaz.autxml.enabled', false);
        }

        if ($this === self::CteAutXmlDistDfe) {
            if (config('sefaz.cte_autxml.kill_switch', false)) {
                return false;
            }

            return (bool) config('sefaz.cte_autxml.enabled', false)
                && (bool) config('sefaz.cte_enabled', false);
        }

        if ($this === self::EmitterPush) {
            return (bool) config('sefaz.cte_emitter_push.enabled', false);
        }

        return (bool) config($this->featureFlagKey(), false);
    }

    /**
     * Canais baseados em NSU (não misturar com posição nNF).
     */
    public function usesNsuCursor(): bool
    {
        return ! in_array($this, [self::MaOutbound, self::ImportXml, self::EmitterPush], true);
    }

    /**
     * Cursor central do escritório (sem establishment_id).
     */
    public function usesOfficeCursor(): bool
    {
        return in_array($this, [self::NfeAutXmlDistDfe, self::CteAutXmlDistDfe], true);
    }

    /**
     * Canais provisionados/avaliados por estabelecimento (não inclui cursor de escritório).
     *
     * @return list<self>
     */
    public static function operationalCases(): array
    {
        return [
            self::NfseAdn,
            self::NfeDistDfe,
            self::CteDistDfe,
            self::MaOutbound,
        ];
    }

    /**
     * Canais com cursor central do escritório (sem establishment_id).
     *
     * @return list<self>
     */
    public static function officeCursorCases(): array
    {
        return [
            self::NfeAutXmlDistDfe,
            self::CteAutXmlDistDfe,
        ];
    }
}
