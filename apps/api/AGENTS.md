# AGENTS.md — apps/api

Deltas locais. Raiz: `/AGENTS.md`.

## Escopo

Laravel 13 / PHP 8.4. Tenant = `Office`. Services + contracts + jobs. Não tratar como app fora do Compose.

## Agente preferido

- Geral: `laravel_api` / `laravel-api`
- SERPRO/Integra/MEI/ADN/SEFAZ/vault fiscal: `fiscal_integrations` / `fiscal-integrations`

## Gates

```bash
composer validate --strict --no-check-publish
vendor/bin/pint --test
php artisan test
```

Preferir Unit em `tests/Unit/**`; Feature HTTP quando contrato/persistência mudar.

## Never

- Commitar `.env`, `auth.json`, certs, vault, `storage/app/private`
- Abrir kill switches / flags MEI-SEFAZ em defaults de exemplo
- Adicionar serviços `mei`/`mei-worker` (isso é infra — mas rejeitar se pedido aqui)
- Implementar sem OpenSpec + testes (salvo skip explícito do usuário)
