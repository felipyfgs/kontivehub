<?php

namespace App\Console\Commands;

use App\Services\Communication\Migrations\RichContentBackfill;
use Illuminate\Console\Command;

final class BackfillCommunicationRichContentCommand extends Command
{
    protected $signature = 'communication:backfill-rich-content
        {--office= : ID do Office obrigatório}
        {--after=0 : Retomar depois deste ID}
        {--chunk=200 : Tamanho do lote}
        {--max= : Máximo de linhas nesta execução}';

    protected $description = 'Cifra conteúdo rico legado de mensagens, isolado por Office e sem efeitos de negócio';

    public function handle(RichContentBackfill $backfill): int
    {
        $officeId = filter_var($this->option('office'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($officeId === false) {
            $this->error('--office é obrigatório e deve ser positivo.');

            return self::INVALID;
        }
        $maximum = $this->option('max');
        $result = $backfill->run(
            $officeId,
            max(0, (int) $this->option('after')),
            max(1, (int) $this->option('chunk')),
            $maximum === null ? null : max(0, (int) $maximum),
        );
        $this->line(json_encode($result, JSON_THROW_ON_ERROR));

        return $result['conflicts'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
