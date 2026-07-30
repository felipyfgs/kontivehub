## 1. Contrato e configuração nativa

- [x] 1.1 Remover as quatro chaves de ativação/rollout de fotos dos exemplos e de `config/communication.php`, preservando somente limites operacionais.
- [x] 1.2 Excluir `CommunicationProfilePictureRollout` e fazer Resources, stream, job e dispatcher derivarem disponibilidade de tenant/inbox/sessão reais.
- [x] 1.3 Tornar `profile_picture_url` sempre nullable e adicionar `profile_picture_state` ao contrato público/tipos gerados com testes de compatibilidade.

## 2. Aquisição segura e lifecycle Laravel

- [x] 2.1 Substituir allowlist configurável por política fixa `whatsapp.net`/subdomínios mantendo todas as defesas SSRF e testes negativos.
- [x] 2.2 Integrar a migration e o asset privado existente, garantindo promoção/invalidação/clear idempotentes e entrega autorizada com ETag.
- [x] 2.3 Disparar refresh due-only em contato, conversa, mensagem e `picture_id`, publicando atualização sanitizada after-commit.
- [x] 2.4 Ampliar reconciliação/dispatcher para profiles sem conversa, com prioridade por atividade, cota por inbox e limite global.

## 3. Wazync e frontend

- [x] 3.1 Aplicar deadline de 80 segundos somente a `PROFILE_PICTURE` e ajustar o timeout Laravel para 90 segundos, com testes dos dois consumidores.
- [x] 3.2 Consumir estado/URL estáveis e atualização realtime em lista, timeline, contexto, catálogo e detalhe, preservando fallback e master-detail.
- [x] 3.3 Remover fixtures/interceptações de foto/Communication do aceite Playwright e preparar validação com backend real.

## 4. Verificação

- [x] 4.1 Rodar testes focados API, Web e Wazync e validar a change OpenSpec em modo strict.
- [x] 4.2 Rodar os gates completos dos três apps e corrigir regressões dentro do escopo.
- [x] 4.3 Subir a stack do checkout, aplicar/reconciliar/backfill e validar visualmente desktop/mobile sem mocks.
- [ ] 4.4 Executar um único smoke real idempotente no contato terminado em `2709` e registrar evidências sanitizadas.
