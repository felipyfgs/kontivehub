## 1. Persistência e configuração fail-closed

- [x] 1.1 Criar migration reversível para estado, objeto cifrado, metadados, versão e timestamps da foto em `communication_inbox_identity_profiles`
- [x] 1.2 Adicionar enum, casts e invariantes do ciclo `UNKNOWN`, `PENDING`, `READY`, `UNAVAILABLE` e `FAILED`
- [x] 1.3 Adicionar configuração e exemplos fail-closed para capability, kill switch, allowlists, TTLs, limites e timeouts
- [x] 1.4 Registrar port e adapter de download no container sem habilitar egress real
- [x] 1.5 Cobrir migration, defaults e compatibilidade do model com testes focados

## 2. Aquisição segura no Laravel

- [x] 2.1 Implementar o port de download e DTO de resultado sem persistir ou registrar a URL remota
- [x] 2.2 Implementar adapter cURL restrito a HTTPS/443, host allowlisted e DNS composto somente por IPs públicos fixados
- [x] 2.3 Desabilitar redirects, cookies e credenciais e aplicar verificação TLS, connect timeout e timeout total explícitos
- [x] 2.4 Limitar o stream a 2 MiB e validar header, magic bytes, MIME e dimensões de JPEG, PNG e WebP até 4096×4096
- [x] 2.5 Cobrir SSRF, DNS misto, redirects, timeout, status transitórios, MIME, assinatura, tamanho e dimensões com testes

## 3. Refresh, versionamento e fila

- [x] 3.1 Implementar serviço de aquisição que consulta `PROFILE_PICTURE` com `preview=true` e grava bytes no `CommunicationMediaStore`
- [x] 3.2 Implementar job único na fila `communication` com snapshot, lock, retries/backoff finitos e descarte de resultado obsoleto
- [x] 3.3 Promover swaps transacionais e idempotentes e remover objetos substituídos ou não promovidos após commit
- [x] 3.4 Projetar mudança/clear de `picture_id` em ordem, esconder asset incoerente e disparar refresh somente após commit
- [x] 3.5 Cobrir cache positivo/negativo, nil/privacidade, falha transitória, retry idempotente, evento fora de ordem e mudança durante fetch

## 4. Backfill e lifecycle

- [x] 4.1 Disparar refresh assíncrono após a primeira conversation WhatsApp materializada para uma identity autorizada
- [x] 4.2 Implementar despachante limitado a 100 jobs globais e 25 por inbox, priorizando activity recente
- [x] 4.3 Agendar reconciliação a cada quinze minutos com `withoutOverlapping` e `onOneServer`
- [x] 4.4 Integrar assets aos fluxos de merge, invalidação, purge e exportação segura de metadados
- [x] 4.5 Cobrir defaults no-op, limites do backfill, merge coerente, clear, purge com retry e export sem dados secretos

## 5. Resolução e API pública

- [x] 5.1 Resolver foto de conversation pela combinação exata inbox+identity canônica sem N+1
- [x] 5.2 Resolver foto de contato pela conversation canônica visível mais recente com desempate determinístico e sem inbox oculta
- [x] 5.3 Adicionar `profile_picture_url` opcional e compatível aos Resources de contato e conversation
- [x] 5.4 Implementar GET autenticado da imagem com autorização por inbox, 404 uniforme, ETag, revalidação privada e `nosniff`
- [x] 5.5 Cobrir tenancy, visibilidade da inbox, aliases PN/LID, versão divergente, 304, ausência de objeto e perda de acesso

## 6. Contratos Laravel–Wazync e API–SPA

- [x] 6.1 Preservar a query administrativa e validar `PROFILE_PICTURE` com `preview=true`, nil, timeout e erro sanitizado no Wazync
- [x] 6.2 Garantir que logs e contratos Wazync não exponham `DirectPath`, hash, URL remota ou JID em falhas
- [x] 6.3 Atualizar o OpenAPI público com os campos opcionais e resposta binária autenticada da nova rota
- [x] 6.4 Regenerar o contrato público e os tipos TypeScript pelo fluxo canônico
- [x] 6.5 Executar testes de compatibilidade nos dois consumidores e `make wazync-test`

## 7. Interface de conversations e contatos

- [x] 7.1 Exibir foto same-origin e fallback por iniciais na lista, navbar/timeline e contexto da conversation
- [x] 7.2 Exibir foto same-origin e fallback por iniciais ou `?` no catálogo e detalhe do contato, preservando 42 px `rounded-lg`
- [x] 7.3 Tornar avatar da lista wrapper relativo e sobrepor o checkbox central em hover, foco, seleção e ponteiro coarse
- [x] 7.4 Manter checkbox, botão de abertura e menu como irmãos e impedir clique, Space ou Enter de alterar rota/conversation
- [x] 7.5 Cobrir foto/fallback, responsividade, temas, acessibilidade, altura fixa, seleção e virtualização em testes Web

## 8. Validação e rollout

- [x] 8.1 Executar testes focados da API, Web e Wazync e corrigir regressões encontradas
- [x] 8.2 Executar `composer validate`, Pint e a suíte PHPUnit completa da API
- [x] 8.3 Executar lint, typecheck, generate, testes unitários, fidelity e artifacts da Web
- [x] 8.4 Validar manualmente superfícies responsivas e estados claro/escuro sem ativar egress ou flags reais
- [x] 8.5 Validar a change com OpenSpec strict e documentar ordem de deploy, ativação posterior e rollback não destrutivo
