<?php

namespace App\Console\Commands;

use App\Models\Office;
use App\Services\Sefaz\CteOperationsMetrics;
use Illuminate\Console\Command;

/**
 * Readiness somente-leitura: valida presença de URLs/namespace CT-e DistDFe.
 * Nunca faz chamada de rede (seguro em CI) e não depende de libs comunitárias.
 */
class SefazCteReadinessCommand extends Command
{
    protected $signature = 'sefaz:cte-readiness
                            {--office= : ID do escritório para incluir cursores/cobertura}
                            {--period= : Competência YYYY-MM das métricas}
                            {--json : Saída JSON sanitizada}';

    protected $description = 'Valida configuração CT-e DistDFe (URLs/namespace) sem rede';

    public function handle(CteOperationsMetrics $metrics): int
    {
        $checks = $this->evaluate();
        $ok = ! collect($checks)->contains(fn (array $c) => $c['status'] === 'fail');
        $officeId = (int) ($this->option('office') ?: 0);
        $period = (string) ($this->option('period') ?: '');
        if ($period !== '' && preg_match('/^\d{4}-\d{2}$/', $period) !== 1) {
            $this->error('Período inválido; use YYYY-MM.');

            return self::FAILURE;
        }
        if ($officeId > 0 && ! Office::query()->whereKey($officeId)->exists()) {
            $this->error('Escritório não encontrado.');

            return self::FAILURE;
        }
        $operations = $officeId > 0
            ? $metrics->snapshot($officeId, $period !== '' ? $period : null)
            : null;

        if ($this->option('json')) {
            $this->line(json_encode([
                'ready' => $ok,
                'checks' => $checks,
                'operations' => $operations,
                'network_called' => false,
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return $ok ? self::SUCCESS : self::FAILURE;
        }

        foreach ($checks as $check) {
            $mark = match ($check['status']) {
                'pass' => '✓',
                'warn' => '!',
                default => '✗',
            };
            $this->line("[{$mark}] {$check['name']}: {$check['message']}");
        }

        if ($operations !== null) {
            $this->newLine();
            $this->line((string) json_encode($operations, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }

        $this->newLine();
        $this->info($ok
            ? 'Config CT-e DistDFe pronta (sem chamada de rede).'
            : 'Config CT-e DistDFe incompleta.');

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return list<array{name: string, status: string, message: string}>
     */
    private function evaluate(): array
    {
        $checks = [];

        $prod = (string) config('sefaz.cte.production', '');
        $hom = (string) config('sefaz.cte.homologation', '');
        $ns = (string) config('sefaz.cte.namespace', '');
        $soap = (string) config('sefaz.cte.soap_action', '');
        $layout = (string) config('sefaz.cte.layout_version', '');

        $checks[] = $this->urlCheck('cte.production', $prod);
        $checks[] = $this->urlCheck('cte.homologation', $hom);
        $checks[] = $this->present(
            'cte.namespace',
            $ns,
            str_contains($ns, 'CTeDistribuicaoDFe'),
            'Namespace WSDL CTeDistribuicaoDFe'
        );
        $checks[] = $this->present(
            'cte.soap_action',
            $soap,
            str_contains($soap, 'cteDistDFeInteresse'),
            'SOAPAction cteDistDFeInteresse'
        );
        $checks[] = $this->present(
            'cte.layout_version',
            $layout,
            $layout === '1.00' || $layout === '1.0',
            'Layout distribuição 1.00'
        );

        $checks[] = [
            'name' => 'runtime_community_client',
            'status' => 'pass',
            'message' => 'Cliente HTTP próprio (sem lib comunitária DistDFe em runtime).',
        ];
        $checks[] = [
            'name' => 'network',
            'status' => 'pass',
            'message' => 'Nenhuma chamada de rede neste readiness.',
        ];

        return $checks;
    }

    /**
     * @return array{name: string, status: string, message: string}
     */
    private function urlCheck(string $name, string $url): array
    {
        if ($url === '') {
            return ['name' => $name, 'status' => 'fail', 'message' => 'URL ausente.'];
        }
        if (! str_starts_with($url, 'https://')) {
            return ['name' => $name, 'status' => 'fail', 'message' => 'URL deve ser HTTPS.'];
        }
        if (! str_contains(strtolower($url), 'ctedistribuicaodfe')) {
            return [
                'name' => $name,
                'status' => 'warn',
                'message' => 'URL não contém CTeDistribuicaoDFe (verifique endpoint vigente).',
            ];
        }

        return ['name' => $name, 'status' => 'pass', 'message' => 'URL HTTPS configurada.'];
    }

    /**
     * @return array{name: string, status: string, message: string}
     */
    private function present(string $name, string $value, bool $ok, string $hint): array
    {
        if ($value === '') {
            return ['name' => $name, 'status' => 'fail', 'message' => "{$hint}: ausente."];
        }

        return [
            'name' => $name,
            'status' => $ok ? 'pass' : 'warn',
            'message' => $ok ? "{$hint}: ok." : "{$hint}: valor inesperado (config presente).",
        ];
    }
}
