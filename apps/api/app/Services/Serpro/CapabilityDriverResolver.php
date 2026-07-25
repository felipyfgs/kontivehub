<?php

namespace App\Services\Serpro;

use App\Contracts\ResolvesSerproCapabilityDriver;
use App\Enums\FiscalProfile;
use App\Enums\SerproCapabilityDriver;

/**
 * O perfil fiscal é a única seleção de transporte: dev=fixture, trial/prod=real.
 */
final class CapabilityDriverResolver implements ResolvesSerproCapabilityDriver
{
    /**
     * Prefixo de operation_key → chave de config.
     *
     * @var array<string, string>
     */
    private const PREFIX_CAPABILITY = [
        'sitfis.' => 'sitfis',
        'autentica_procurador.' => 'autentica_procurador',
        'autenticaprocurador.' => 'autentica_procurador',
        'procuracoes.' => 'authorization',
        'eventosatualizacao.' => 'authorization',
        'caixa_postal.' => 'mailbox',
        'dte.' => 'mailbox',
        'dctfweb.' => 'dctfweb',
        'mit.' => 'dctfweb',
        'pgdasd.' => 'simples_mei',
        'pgmei.' => 'simples_mei',
        'ccmei.' => 'simples_mei',
        'defis.' => 'simples_mei',
        'regimeapuracao.' => 'simples_mei',
        'dasnsimei.' => 'simples_mei',
        'parc' => 'installments', // parcmei., parcsn., etc.
        'pert' => 'installments',
        'relp' => 'installments',
        'sicalc.' => 'guides',
        'pagtoweb.' => 'guides',
        'pnr_contador.' => 'registrations',
        'eprocesso.' => 'tax_processes',
    ];

    public function forOperationKey(string $operationKey): SerproCapabilityDriver
    {
        return $this->forCapability($this->capabilityForOperationKey($operationKey));
    }

    public function capabilityForOperationKey(string $operationKey): string
    {
        $key = strtolower(trim($operationKey));
        foreach (self::PREFIX_CAPABILITY as $prefix => $capability) {
            if (str_starts_with($key, $prefix)) {
                return $capability;
            }
        }

        return 'default';
    }

    public function forCapability(string $capability): SerproCapabilityDriver
    {
        return match (FiscalProfile::configured()) {
            FiscalProfile::Dev => SerproCapabilityDriver::Fixture,
            FiscalProfile::Trial, FiscalProfile::Production => SerproCapabilityDriver::Real,
        };
    }

    /**
     * Detecta configuração simulated em qualquer ambiente.
     *
     * @return list<string> problemas (vazio = ok)
     */
    public function preflightProduction(): array
    {
        return [];
    }

    public function assertProductionSafe(): void
    {
        FiscalProfile::configured();
    }

    /**
     * @return list<string>
     */
    public function knownCapabilities(): array
    {
        return array_values(array_unique(array_merge(
            array_values(self::PREFIX_CAPABILITY),
            ['default'],
        )));
    }
}
