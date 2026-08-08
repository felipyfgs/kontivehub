# KontiveHub

Plataforma multi-tenant de gestão fiscal, documentos, comunicação e trabalho
para escritórios contábeis. Integrações externas e operações sensíveis usam
flags fail-closed e permanecem desativadas por padrão.

## Tecnologias

| Camada | Tecnologias principais |
| --- | --- |
| API | PHP 8.4, Laravel 13, Horizon e Reverb |
| Web | Nuxt 4, Vue 3, Nuxt UI e TypeScript |
| Comunicação | Go 1.25 e whatsmeow |
| Dados | PostgreSQL 17 e Redis 8 |
| Infraestrutura | Docker Compose, Docker Swarm e Nginx |
| Testes | PHPUnit, Vitest e Playwright |

## Estrutura

```text
.
├── apps/
│   ├── api/                 # Laravel e Dockerfile da API/API-RPA
│   ├── web/                 # Nuxt e Dockerfile do frontend
│   └── wazync/              # gateway Go e seu Dockerfile
├── docker/nginx/            # imagem do proxy interno
├── docker-compose.yml       # desenvolvimento com hot reload
├── docker-compose.test.yml  # testes herméticos e descartáveis
├── docker-compose.prod.yml  # produção Docker Compose
└── docker-compose.swarm.yaml # produção Docker Swarm
```

Cada aplicação possui sua própria imagem. PostgreSQL e Redis são serviços
separados baseados nas imagens oficiais; eles nunca são incorporados às imagens
da API, Web ou Wazync.

## Desenvolvimento local

Pré-requisitos: Git, Docker com o plugin Compose v2.20 ou superior (necessário
para `docker compose up --wait`) e OpenSSL.

```bash
git clone https://github.com/felipyfgs/kontivehub.git
cd kontivehub
cp .env.example .env
chmod 600 .env
```

Edite `LOCAL_UID` e `LOCAL_GID` conforme `id -u` e `id -g`. Gere as duas chaves
locais obrigatórias; os comandos abaixo apenas imprimem os valores, então copie
cada linha para a chave correspondente no `.env`:

```bash
printf 'APP_KEY=base64:%s\n' "$(openssl rand -base64 32)"
printf 'VAULT_MASTER_KEY=%s\n' "$(openssl rand -base64 32)"
```

Depois suba o ambiente:

```bash
docker compose up -d --build --wait
docker compose exec api php artisan migrate --force
```

O entrypoint instala Composer e pnpm na primeira execução. Nuxt usa HMR,
Laravel lê o código montado e Air recompila o Wazync após mudanças em arquivos
Go.

- Web/HMR: [http://localhost:3000](http://localhost:3000)
- API/Nginx: [http://localhost:8080](http://localhost:8080)
- Healthcheck: [http://localhost:8080/up](http://localhost:8080/up)

Comandos comuns:

```bash
docker compose logs -f
docker compose exec api sh
docker compose exec api php artisan db:seed --force
docker compose down --remove-orphans
```

## Testes e qualidade

```bash
docker compose exec api composer validate --strict --no-check-publish
docker compose exec api vendor/bin/pint --test
docker compose exec api php artisan test
docker compose exec web app-entrypoint test-gate
docker run --rm -v "$PWD:/workspace" -w /workspace/apps/wazync golang:1.25-alpine go test ./...
docker run --rm -v "$PWD:/workspace" -w /workspace/apps/wazync golang:1.25-alpine go vet ./...
./docker/nginx/verify-upstream-recovery.sh
```

Para reproduzir todos os gates em imagens construídas exclusivamente a partir
do checkout, sem reutilizar o `.env`, portas ou volumes persistentes locais:

```bash
./docker/test-gate.sh
```

O script constrói os targets `test` da API, Web e Wazync, inicia PostgreSQL e
Redis descartáveis, executa os três gates e remove todo o projeto Compose ao
terminar. Cada execução recebe um project name exclusivo; defina
`KONTIVEHUB_TEST_PROJECT` apenas quando precisar de um nome estável e isolado.
Use o script como ponto de entrada para impedir que o `COMPOSE_PROJECT_NAME` do
ambiente de desenvolvimento seja reutilizado acidentalmente.

## Configuração

O `.env` da raiz é a fonte de valores dos manifestos de desenvolvimento e
produção. O manifesto de testes não consome esse arquivo. Os YAMLs
declaram explicitamente as variáveis entregues a cada serviço e usam referências
`${VAR}`. Domínios públicos possuem defaults reservados como
`app.example.com`; senhas, tokens e chaves obrigatórias não têm default de
produção.

O arquivo `.env.example` é seguro para versionamento, mas não é implantável em
produção. O Dockerfile nunca copia o `.env` para as imagens.

Para os contratos outbound de comunicação, limites, motivos de capability e a
configuração opcional de GIF, consulte
[docs/communication.md](docs/communication.md).

O NATS externo de produção deve exigir usuário e senha e limitar os subjects de
eventos e comandos aos workloads autorizados. Os manifests recusam
`COMMUNICATION_NATS_USER` ou `COMMUNICATION_NATS_PASSWORD` vazios; configure a
mesma credencial no serviço NATS antes do rollout.

## Produção com Docker Compose

Configure no `.env` as URLs reais, credenciais, tag imutável e token do proxy.
O manifesto produtivo apenas baixa imagens do registry:

```bash
docker compose --env-file .env -f docker-compose.prod.yml config --quiet
docker compose --env-file .env -f docker-compose.prod.yml \
  up -d --pull always --wait --remove-orphans
```

## Produção com Docker Swarm

O Swarm não lê `.env` automaticamente. Exporte o arquivo no shell antes do
deploy (mantenha o `.env` compatível com a sintaxe `CHAVE=valor` do POSIX).
Antes do primeiro deploy, rotule exatamente o nó que manterá os volumes locais:

```bash
docker node update --label-add kontivehub-data=true NOME_DO_NO
set -a
. ./.env
set +a
docker stack deploy --with-registry-auth -c docker-compose.swarm.yaml kontivehub
```

O `docker-compose.swarm.yaml` mantém os serviços que usam volumes no nó rotulado, define
overlay interno criptografado, healthchecks, limites, atualização e rollback.
Faça backup dos volumes desse nó. Para reverter, ajuste `KONTIVEHUB_VERSION`
para uma tag anterior e repita o deploy. TLS deve terminar no ingress externo.
Esse ingress deve remover qualquer `X-KontiveHub-Edge-Token` enviado pelo
cliente e redefini-lo exclusivamente com `NGINX_EDGE_TOKEN`. Bloqueie por
firewall ou rede privada todo acesso direto à porta publicada pelo Nginx da
stack; somente o ingress confiável pode alcançá-la.

Variáveis de ambiente são visíveis a administradores por `docker inspect` e
`docker service inspect`; não coloque o `.env` no Git nem em artefatos da CI.

## Entrega contínua

Pull requests entram em `develop`; somente `develop` promove para `main`. Após
o CI verde na `main`, a pipeline constrói os targets finais multi-stage e
publica `api`, `api-rpa`, `web`, `nginx` e `wazync` no GHCR com uma tag
imutável. Os manifestos de produção nunca compilam ou montam o código-fonte.

Consulte [CONTRIBUTING.md](CONTRIBUTING.md) antes de abrir um pull request.
