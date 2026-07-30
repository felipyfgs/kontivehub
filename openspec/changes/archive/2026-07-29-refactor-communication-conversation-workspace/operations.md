# Readiness operacional — conversation workspace

## Sequência de rollout

1. Aplicar migrations aditivas (ledger por mensagem e read-state versionado). As tabelas começam vazias; histórico existente é tratado como lido (sem backfill de unread).
2. Publicar Laravel aceitando perfil antigo/novo (`display_name` legado + campos separados) e mantendo respostas legadas de listagem/detalhe durante a janela de compatibilidade.
3. Publicar Wazync com `CONTACT_PROFILES` e eventos tipados de perfil/clear/`JIDAlt`/`READ_SELF`; reconciliar somente identities já conhecidas no store local.
4. Publicar Nuxt com detalhe sem mensagens embutidas (`include_messages=false`), timeline cursorizada e read-state (auto-read, filtro unread, pin da seleção).
5. Monitorar reconciliação de perfis, conflitos `READ_STATE_VERSION_CONFLICT`, crescimento do ledger, outbox e realtime com labels de baixa cardinalidade (sem corpo de mensagem, JID cru ou CNPJ).

## Rollback

- **Antes de estado real** (ledger/read-state/perfis ainda vazios em produção): é seguro dropar apenas as tabelas novas introduzidas por este change.
- **Depois de estado real**: a recuperação segura é **roll-forward**. Não dropar tabelas com dados de ledger/read-state/perfil em uso.
- Wazync pode voltar a emitir só `display_name` enquanto a API Laravel compatível permanecer publicada.
- Frontend novo depende da API com timeline/read-state; reverter a SPA antes da API se for necessário abortar a UX nova.

## Contrato e ordem de dependência

```
migrations (API) → Laravel API → Wazync → Nuxt SPA → monitor
```

Contratos afetados: `/api/v1/communication` e `apps/api/resources/contracts/wazync.openapi.yaml` (dois consumidores). Publicar API compatível antes do Wazync novo e do frontend.

## Evidências dos gates

- API: Composer strict e Pint em 3.300 arquivos; PHPUnit completo com 1.272 testes e 17.777 asserções.
- Web: lint, typecheck, generate, 121 arquivos/614 testes, fidelity 74/74 e artifacts em 439 arquivos sem material sensível.
- Playwright: 12/12 cenários de comunicação passaram, cobrindo lista/timeline, teclado/foco, desktop/mobile, claro/escuro, contatos e operações bulk.
- Wazync: `go test ./...` e `go vet ./...` concluídos sem falhas em execução isolada.
- OpenSpec: change validada em modo strict após o fechamento do checklist.
