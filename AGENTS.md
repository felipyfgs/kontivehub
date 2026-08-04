# Guia do repositório

KontiveHub é uma plataforma contábil multi-tenant. Preserve isolamento por
tenant, autorização, contratos e ownership entre aplicações.

## Instruções

- Leia este arquivo e o `AGENTS.md` mais próximo do código em escopo.
- O pedido do usuário prevalece; instruções locais complementam a raiz.
- Para trabalho não trivial, leia `.codex/ORCHESTRATION.md` e delegue ao menos
  uma subtarefa delimitada.
- Leia integralmente skills aplicáveis e artefatos OpenSpec citados pela tarefa.
- Preserve alterações alheias; nunca reverta ou inclua trabalho não autorizado.

O principal/default orquestra requisitos, ownership, dependências, integração e
resposta final. Não implemente sozinho trabalho delegável. Toda edição de
subagente é provisória até o principal revisar o diff real e aceitá-la. Tarefas
triviais podem ficar no principal quando delegar não trouxer valor independente.

## Estrutura e limites

- `apps/api/`: Laravel 13, domínio, contratos, migrations e testes.
- `apps/web/`: Nuxt 4/Vue 3; siga também `apps/web/AGENTS.md`.
- `apps/wazync/`: gateway técnico WhatsApp em Go, sem domínio contábil.
- `docker/`, Compose e `.github/workflows/`: infraestrutura e entrega.
- Laravel é dono do domínio; o Web nunca chama Wazync diretamente.
- Mudanças de contrato exigem validação dos dois consumidores.
- Integrações externas e mutações fiscais permanecem fail-closed sem rollout
  explicitamente aprovado.

## Gates

Rode testes focados e os gates de todas as áreas afetadas. O CI é a fonte de
verdade.

```bash
docker compose up -d --build --wait

docker compose exec api composer validate --strict --no-check-publish
docker compose exec api vendor/bin/pint --test
docker compose exec api php artisan test

docker compose exec web app-entrypoint test-gate

cd apps/wazync && go test ./... && go vet ./...

./docker/test-gate.sh
```

Use quatro espaços em PHP, dois em Vue/TypeScript e `gofmt` em Go. Testes usam
`*Test.php`, `*.test.ts` e `*_test.go`. Priorize regressão, tenant, autorização
e falhas.

## Git e segurança

- Conventional Commits em pt-BR; branches partem de `develop` com
  `feature/*`, `fix/*`, `refactor/*`, `chore/*` ou `hotfix/*`.
- PRs apontam para `develop`; somente `develop` promove para `main`.
- Não faça commit, push, merge, rebase ou abra PR sem pedido explícito.
- Nunca versione `.env`, tokens, chaves, PFX/PEM, payloads fiscais ou
  credenciais de produção.
- No handoff, informe resumo, arquivos, gates e riscos; inclua screenshots para
  mudanças visuais.
