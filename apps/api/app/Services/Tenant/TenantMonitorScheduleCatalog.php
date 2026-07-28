<?php

namespace App\Services\Tenant;

final class TenantMonitorScheduleCatalog
{
    /** @var array<string, string> */
    private const LABELS = [
        'sitfis' => 'Situação fiscal',
        'simples_mei' => 'Simples / MEI',
        'dctfweb' => 'DCTFWeb / MIT',
        'installments' => 'Parcelamentos',
        'mailbox' => 'Caixa postal',
        'declarations' => 'Declarações',
        'guides' => 'Guias',
        'fgts' => 'FGTS (parcial)',
    ];

    /** @return array<string, string> */
    public function all(): array
    {
        return self::LABELS;
    }

    public function label(string $monitorKey): ?string
    {
        return self::LABELS[$monitorKey] ?? null;
    }
}
