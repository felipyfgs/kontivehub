# whatsapp-message-projection-integrity Specification

## Purpose

Garantir integridade da projeção de mensagens WhatsApp: frames técnicos fora do
ledger conversacional, MESSAGE_RECEIVED coerente, reentrega enriquecedora sem
duplicar, timeline pública sem balão vazio e quarentena de legado determinística
e auditável.

## Requirements

### Requirement: Frames técnicos não entram no ledger de mensagens
O Wazync SHALL classificar todo frame 1:1 de ação, controle ou escopo não conversacional antes da projeção de conteúdo e SHALL NOT emitir `MESSAGE_RECEIVED` para `ProtocolMessage`, `ACTION`, `CONTROL` ou `OUT_OF_SCOPE`.

#### Scenario: Revogação ou edição suportada
- **WHEN** chega `ProtocolMessage` de revogação ou edição com alvo válido
- **THEN** o gateway emite somente o evento de ação tipado correspondente e nenhuma nova mensagem de conversa

#### Scenario: Controle de sync, app-state ou segurança
- **WHEN** chega um `ProtocolMessage` técnico sem conteúdo conversacional
- **THEN** o gateway o consome ou projeta como evento técnico allowlisted sem criar balão, preview ou atividade de conversa

#### Scenario: Subtipo de protocolo desconhecido
- **WHEN** o protobuf fixado contém um subtipo não mapeado
- **THEN** o gateway rejeita a projeção conversacional, incrementa somente métrica sanitizada e não persiste payload bruto

#### Scenario: Mesma regra em history
- **WHEN** um frame de ação ou controle aparece em history sync
- **THEN** ele recebe a mesma disposição do fluxo live e não entra no lote de mensagens

### Requirement: MESSAGE_RECEIVED possui representação semântica válida
O contrato Laravel–Wazync e o ingestor SHALL aceitar `MESSAGE_RECEIVED` somente quando `kind`, `family`, provider type e conteúdo forem coerentes, preservando fail-closed e o escopo WhatsApp 1:1.

#### Scenario: Texto projetável
- **WHEN** o evento declara `kind=TEXT` e `family=TEXT`
- **THEN** ele contém `text` não vazio e a API persiste corpo e conteúdo semântico equivalentes

#### Scenario: Mídia histórica sem stream imediato
- **WHEN** history contém mídia cujo descriptor técnico foi preservado mas nenhum stream foi baixado
- **THEN** o evento contém kind de mídia e `media_state=RETRY_AVAILABLE`, permitindo persistência sem fingir que o conteúdo está disponível

#### Scenario: Conteúdo real ainda não suportado
- **WHEN** uma mensagem conversacional desconhecida possui conteúdo real mas não possui projeção específica
- **THEN** `UNSUPPORTED` exige `family=UNSUPPORTED` e `content_present=true`, e a API a representa explicitamente como não suportada

#### Scenario: Família de ação tenta usar MESSAGE_RECEIVED
- **WHEN** o evento declara `family=ACTION`, `CONTROL` ou `OUT_OF_SCOPE`, ou usa provider type de protocolo
- **THEN** o contrato rejeita o evento sem criar ou alterar contact, identity, conversation, message ou unread

### Requirement: Reentrega enriquece sem apagar ou duplicar
A API SHALL serializar por inbox/provider ID e SHALL enriquecer somente campos ausentes de uma mensagem existente quando nova evidência coerente trouxer conteúdo, reply, estado ou attachment.

#### Scenario: Evento incompleto seguido de conteúdo completo
- **WHEN** a mesma mensagem chega depois com corpo, conteúdo ou mídia válidos antes ausentes
- **THEN** a row existente é enriquecida sob lock, preservando o ID interno e sem criar segunda mensagem

#### Scenario: Evento parcial atrasado
- **WHEN** uma reentrega posterior omite campo que já está preenchido
- **THEN** corpo, conteúdo, attachment, status bem-sucedido e disponibilidade existentes são preservados

#### Scenario: Conteúdo não vazio diverge
- **WHEN** o mesmo provider ID chega para outro peer/direction ou com conteúdo não vazio conflitante
- **THEN** a ingestão falha fechada e não realiza merge parcial

#### Scenario: Enriquecimento de inbound existente
- **WHEN** uma inbound já materializada recebe enriquecimento
- **THEN** unread, automação, atividade e eventos de criação não são duplicados

### Requirement: Timeline pública nunca apresenta balão vazio como mensagem normal
A API pública SHALL expor estado aditivo de disponibilidade e a SPA SHALL renderizar corpo/conteúdo, placeholder explícito ou estado de mídia para toda mensagem visível; rows técnicas quarentenadas SHALL NOT entrar em timeline ou preview.

#### Scenario: Conteúdo disponível
- **WHEN** `body`, `content.text`, `content.caption` ou attachment está disponível
- **THEN** a timeline apresenta o conteúdo correspondente e o marca como `AVAILABLE`

#### Scenario: Mensagem não suportada ou indisponível
- **WHEN** a API retorna `UNSUPPORTED` ou `UNAVAILABLE`
- **THEN** a SPA mostra um placeholder específico e nunca apenas o cabeçalho “Enviada/Recebida · WhatsApp” com horário

#### Scenario: Controle legado quarentenado
- **WHEN** uma row do gateway é marcada com razão `WHATSAPP_PROTOCOL_CONTROL`
- **THEN** lista, preview, timeline, cursores e contagem ignoram a row sem apagá-la fisicamente

#### Scenario: Atualização parcial no frontend
- **WHEN** sync/realtime entrega a mesma mensagem sem body/content/attachments já conhecidos
- **THEN** o merge do cliente preserva os valores existentes e não transforma o balão em vazio

### Requirement: Quarentena de legado é determinística e auditável
O sistema SHALL oferecer auditoria dry-run e quarentena reversível, tenant-safe e não destrutiva para rows técnicas já materializadas, sem executar a operação automaticamente em migration, deploy ou schedule.

#### Scenario: Auditoria sem execução
- **WHEN** o operador executa a auditoria sem opção explícita de mutação
- **THEN** o sistema retorna apenas contagens e IDs internos elegíveis sem alterar rows ou imprimir conteúdo/endereço

#### Scenario: Quarentena autorizada
- **WHEN** um contexto privilegiado tipado confirma a quarentena de rows com assinatura determinística de `protocolMessage`
- **THEN** somente rows do tenant/inbox selecionado recebem timestamp, razão allowlisted e registro de auditoria

#### Scenario: Tentativa cross-tenant
- **WHEN** a seleção tenta alcançar row fora do tenant confiável informado
- **THEN** a operação falha fechada e nenhuma row é marcada

#### Scenario: Reversão auditada
- **WHEN** a quarentena precisa ser revertida
- **THEN** somente marcas produzidas pela operação identificada são removidas, sem recriar ou apagar mensagens
