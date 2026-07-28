## 1. Backend de autenticação

- [x] 1.1 Adicionar migration reversível para alinhar as tabelas legadas de identidade
- [x] 1.2 Adicionar tradução pt-BR para falhas de autenticação do Fortify
- [x] 1.3 Cobrir migration legada, login inválido localizado e fluxo Sanctum válido

## 2. Bootstrap do frontend

- [x] 2.1 Desabilitar a consulta inicial automática de identidade do módulo Sanctum
- [x] 2.2 Cobrir a configuração que delega a identidade ao middleware de rotas

## 3. Validação local

- [ ] 3.1 Aplicar a migration sem limpar os dados locais
- [ ] 3.2 Validar CSRF, login válido, `/me` autenticado, erro inválido localizado,
  contrato dependente ausente em modo fail-closed, memberships inválidas ou
  cross-tenant, tenant padrão sem membership fabricada, schema híbrido/rollback
  e ausência de `/me` ao abrir rota pública
- [ ] 3.3 Executar os gates focados de API e web
