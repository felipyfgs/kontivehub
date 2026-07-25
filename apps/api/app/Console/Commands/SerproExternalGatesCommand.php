<?php

namespace App\Console\Commands;

use App\Enums\SerproExternalGateKind;
use App\Services\Serpro\SerproExternalGateService;
use Illuminate\Console\Command;

/**
 * Lista/atualiza gates documentais externos (sem segredos).
 */
class SerproExternalGatesCommand extends Command
{
    protected $signature = 'serpro:external-gates
        {action=list : list|submit|seed}
        {--kind= : Kind do gate (enum value)}
        {--ticket= : Referência de chamado/ticket}
        {--evidence= : Referência documental sanitizada}
        {--json : Saída JSON}';

    protected $description = 'Gates documentais SERPRO/jurídico/ops que bloqueiam PRODUCTION_READY';

    public function handle(SerproExternalGateService $gates): int
    {
        $action = strtolower((string) $this->argument('action'));

        return match ($action) {
            'seed', 'list' => $this->doList($gates),
            'submit' => $this->doSubmit($gates),
            default => $this->invalid($action),
        };
    }

    private function doList(SerproExternalGateService $gates): int
    {
        $rows = $gates->listSanitized();
        if ($this->option('json')) {
            $this->line(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->table(
            ['kind', 'status', 'blocks', 'ticket', 'title'],
            collect($rows)->map(fn (array $r) => [
                $r['kind'],
                $r['status'],
                $r['blocks_production'] ? 'yes' : 'no',
                $r['ticket_ref'] ?? '—',
                $r['title'],
            ])->all()
        );

        return self::SUCCESS;
    }

    private function doSubmit(SerproExternalGateService $gates): int
    {
        $kindRaw = (string) $this->option('kind');
        $ticket = (string) $this->option('ticket');
        $kind = SerproExternalGateKind::tryFrom($kindRaw);
        if ($kind === null || $ticket === '') {
            $this->error('Informe --kind=<enum> e --ticket=<ref>.');

            return self::FAILURE;
        }

        $gate = $gates->recordSubmission(
            $kind,
            $ticket,
            $this->option('evidence') ? (string) $this->option('evidence') : null,
        );

        $this->info('Gate atualizado: '.$gate->kind->value.' → '.$gate->status->value);

        return self::SUCCESS;
    }

    private function invalid(string $action): int
    {
        $this->error("Ação inválida: {$action}");

        return self::FAILURE;
    }
}
