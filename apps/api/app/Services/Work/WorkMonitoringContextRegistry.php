<?php

namespace App\Services\Work;

/** Links internos de Monitoramento permitidos para processos operacionais. */
final class WorkMonitoringContextRegistry
{
    /**
     * @var array<string, array{label: string, section: string|null}>
     */
    private const DEFINITIONS = [
        'PGDASD' => ['label' => 'PGDAS-D', 'section' => 'pgdasd'],
        'PGMEI' => ['label' => 'MEI', 'section' => null],
        'INSTALLMENTS' => ['label' => 'Parcelamentos', 'section' => 'installments'],
        'DCTFWEB' => ['label' => 'DCTFWeb', 'section' => 'dctfweb'],
        'FGTS' => ['label' => 'FGTS Digital', 'section' => 'fgts'],
        'MAILBOX' => ['label' => 'Caixas Postais', 'section' => 'mailbox'],
        'SITFIS' => ['label' => 'Situação Fiscal', 'section' => 'sitfis'],
        'DECLARATIONS' => ['label' => 'Declarações', 'section' => 'declarations'],
        'GUIDES' => ['label' => 'Guias', 'section' => 'guides'],
        'TAX_PROCESSES' => ['label' => 'Processos Fiscais', 'section' => 'tax_processes'],
    ];

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys(self::DEFINITIONS);
    }

    public function allows(?string $key): bool
    {
        return $key === null || $key === '' || isset(self::DEFINITIONS[mb_strtoupper($key)]);
    }

    /**
     * @return array{module_key: string, label: string, href: string}|null
     */
    public function forClient(?string $key, int $clientId): ?array
    {
        if ($key === null || trim($key) === '' || $clientId < 1) {
            return null;
        }

        $normalized = mb_strtoupper(trim($key));
        $definition = self::DEFINITIONS[$normalized] ?? null;
        if ($definition === null) {
            return null;
        }

        $base = "/monitoring/clients/{$clientId}";

        return [
            'module_key' => $normalized,
            'label' => $definition['label'],
            'href' => $definition['section'] === null ? $base : "{$base}/{$definition['section']}",
        ];
    }
}
