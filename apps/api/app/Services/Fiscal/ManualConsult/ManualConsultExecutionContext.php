<?php

namespace App\Services\Fiscal\ManualConsult;

use Closure;

/**
 * Contexto efêmero que vincula runs criadas por handlers ao action_id público.
 * Nunca é persistido fora da run e é restaurado mesmo quando o handler falha.
 */
final class ManualConsultExecutionContext
{
    private ?ManualConsultActionDefinition $action = null;

    public function activeAction(): ?ManualConsultActionDefinition
    {
        return $this->action;
    }

    public function within(ManualConsultActionDefinition $action, Closure $callback): mixed
    {
        $previous = $this->action;
        $this->action = $action;

        try {
            return $callback();
        } finally {
            $this->action = $previous;
        }
    }
}
