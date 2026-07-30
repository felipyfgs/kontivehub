## MODIFIED Requirements

### Requirement: Célula de identidade legível
Cada card da lista SHALL apresentar identidade scannable: foto de perfil autorizada quando disponível, iniciais como fallback, nome de exibição, indicação de provisório quando aplicável, telefone primário permitido, clientes vinculados e badge de situação. A foto SHALL preservar o avatar de 42 px `rounded-lg`. A UI SHALL NOT exibir JID, URL remota, `picture_id` ou dados do gateway.

#### Scenario: Contato nomeado com foto
- **WHEN** o contato possui `name` e `profile_picture_url`
- **THEN** o card mostra a foto de 42 px, o nome, o telefone permitido e o status

#### Scenario: Contato nomeado sem foto
- **WHEN** o contato possui nome, mas a foto está ausente ou falha
- **THEN** o card mostra as iniciais do nome sem iniciar fetch direto

#### Scenario: Contato provisório
- **WHEN** o contato é provisório sem nome definitivo
- **THEN** o card usa a foto autorizada ou `?` como fallback e sinal visual de “Sem nome definitivo” sem inventar dados
