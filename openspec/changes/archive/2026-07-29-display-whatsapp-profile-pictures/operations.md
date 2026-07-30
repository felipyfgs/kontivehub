# Readiness operacional — fotos de perfil WhatsApp

## Sequência de deploy

1. Publicar a migration aditiva de `communication_inbox_identity_profiles` com os estados e metadados do asset. Os defaults permanecem sem foto pública e sem aquisição.
2. Publicar Laravel, contrato público e rota autenticada da imagem com `COMMUNICATION_PROFILE_PICTURES_ENABLED=false` e `COMMUNICATION_PROFILE_PICTURES_FETCH_KILL_SWITCH=true`.
3. Publicar Wazync compatível com a query privada já existente `PROFILE_PICTURE`; não há mudança no payload privado nem em `MESSAGE_SEND`.
4. Publicar a SPA que consome somente `profile_picture_url` same-origin e mantém iniciais/`?` quando o campo está ausente, nulo ou falha.
5. Somente em rollout operacional separado e autorizado, configurar hosts e tenants allowlisted, habilitar a capability e retirar o kill switch de fetch para um tenant canário.
6. Acompanhar fila `communication`, estados `UNAVAILABLE`/`FAILED`, retries e crescimento do storage apenas por IDs internos, contagens e códigos sanitizados.

## Ativação fail-closed

- A implementação e o deploy não alteram flags reais nem habilitam egress.
- `enabled=false`, `fetch_kill_switch=true`, `allow_all_tenants=false` e allowlists vazias continuam sendo os defaults seguros.
- A rota pública só serve um asset `READY` coerente após reautorizar tenant, membership e inbox; ausência, purge ou perda de acesso retornam 404 uniforme.
- A liberação deve começar em tenant canário e pode avançar somente após validar disponibilidade do CDN allowlisted, latência, fila, storage e ausência de dados sensíveis em logs.

## Rollback não destrutivo

1. Religar imediatamente `COMMUNICATION_PROFILE_PICTURES_FETCH_KILL_SWITCH=true` para interromper novas aquisições.
2. Desligar `COMMUNICATION_PROFILE_PICTURES_ENABLED=false`; Resources voltam a expor `null` e a SPA usa fallback local.
3. Se necessário, reverter primeiro a SPA e manter API/contratos aditivos publicados para consumidores anteriores.
4. Não dropar colunas, não apagar assets em massa e não reverter migrations depois de existir estado real. Preservar schema e objetos para roll-forward; merge, purge, clear e intents de deleção continuam sendo os únicos lifecycles destrutivos.

## Ordem de dependência

```text
migration → Laravel/API pública → Wazync compatível → Nuxt SPA → ativação canário → monitoramento
```

## Evidências dos gates

- API focada e contratos: 84 testes e 1.686 asserções para fotos, workspace, bulk e compatibilidade.
- API completa: Composer strict e Pint em 3.300 arquivos; PHPUnit com 1.272 testes e 17.777 asserções.
- Web: lint, typecheck, generate, 121 arquivos/614 testes, fidelity 74/74 e artifacts em 439 arquivos sem material sensível.
- QA responsiva: Playwright validou 1440×900, 1024×768, 768×1024 e 390×844 em claro/escuro; o verificador de referência `ui-archetypes` também passou.
- Wazync: `go test ./...` e `go vet ./...` concluídos sem falhas em execução isolada.
- OpenSpec: change validada em modo strict. Nenhuma capability, allowlist ou egress real foi habilitado.
