## MODIFIED Requirements

### Requirement: Foto do contato é consistente entre catálogo e detalhes

Catálogo e perfil principal de `/communication/contacts/:id` SHALL consumir o mesmo `profile_picture_url` e `profile_picture_state` resolvidos pelo Laravel. A SPA SHALL usar a foto somente em `READY`, manter iniciais/`?` nos demais estados ou quando a imagem falhar e MUST NOT consultar Wazync ou CDN do WhatsApp. Evento realtime de nova versão SHALL atualizar as duas superfícies sem dados sintéticos.

#### Scenario: Detalhe aberto a partir do catálogo
- **WHEN** um contato com foto é aberto a partir de um card
- **THEN** o perfil de Detalhes mostra a mesma URL/version da lista sem novo endpoint de descoberta

#### Scenario: Foto fica pronta com catálogo aberto
- **WHEN** o job promove uma versão `READY` e publica evento sanitizado
- **THEN** catálogo e detalhe recarregam a projeção autorizada e exibem a foto real

#### Scenario: Asset deixa de existir
- **WHEN** a imagem responde 404 após purge, clear ou troca de versão
- **THEN** o avatar volta ao fallback e o restante dos dados válidos permanece visível
