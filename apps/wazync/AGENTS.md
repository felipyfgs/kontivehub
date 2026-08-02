# AGENTS.md — apps/wazync

Deltas locais. Raiz: `/AGENTS.md`. ADR: `openspec/adr/0001-separar-atendimento-laravel-do-gateway-whatsapp-go.md`.

## Escopo

Go 1.25, WhatsMeow. Sessões/pareamento/spool. Atendimento de negócio permanece na API Laravel.

## Agentes preferidos

Exploração: `explorer`; implementação: `worker`; review: `reviewer`. Mudanças
críticas de contrato com API → escalar para `expert`.

## Gates

```bash
make verify-wazync
# equivalente: go test ./... && go vet ./... (via Docker no Make)
```

## Never

- Colocar segredos de sessão no git
- Misturar UI Nuxt ou domínio fiscal Laravel neste app
