# Readiness operacional — prevenção de perda de mensagens

## Sequência de rollout

1. Publicar a API receptora com validação semântica, `availability`, quarentena aditiva e suporte ao resultado de retry.
2. Publicar o Wazync com filtro de `ProtocolMessage` e aceitação simultânea dos payloads de retry legado inbound e v2.
3. Publicar a API emissora de retry v2, que envia somente `to`, `target_message_id` e `expected_direction`.
4. Publicar a SPA com placeholders explícitos, merge preservador e recovery condicionado a autorização, inbox operacional e `recoverable=true`.
5. Executar apenas os dry-runs abaixo e revisar as contagens antes de solicitar autorização operacional separada.

Rollback do emissor exige voltar a API ao payload legado antes de reverter o Wazync. A migration é aditiva e não realiza backfill, quarentena ou egress.

## Dry-run de projeções técnicas

O comando exige IDs internos confiáveis e não imprime corpo, endereço, provider ID ou payload do gateway. Sem `--execute`, nenhuma row é alterada.

```bash
docker compose exec -T php php artisan communication:audit-message-projection \
  --tenant=TENANT_INTERNO --inbox=INBOX_INTERNA \
  --operation=auditoria-projecao-AAAAMMDD
```

Para inventariar uma reversão sem aplicá-la:

```bash
docker compose exec -T php php artisan communication:audit-message-projection \
  --tenant=TENANT_INTERNO --inbox=INBOX_INTERNA \
  --operation=auditoria-reversao-AAAAMMDD \
  --reverse=OPERACAO_DE_QUARENTENA
```

## Dry-run de mídia histórica

O inventário não consulta `wazync.*`, não cria outbox e é limitado pelo menor valor entre `--limit` e o limite configurado.

```bash
docker compose exec -T php php artisan communication:rescue-history-media \
  --tenant=TENANT_INTERNO --inbox=INBOX_INTERNA \
  --limit=25 --operation=inventario-midia-AAAAMMDD
```

Resultados contêm somente IDs internos, contagens por direção/kind e códigos allowlisted. `PENDING_RESULT`, `SESSION_LIMIT_REACHED`, `MEDIA_RESCUE_DISABLED` e `INBOX_NOT_OPERATIONAL` interrompem o lote de forma fail-closed.

## Evidências sanitizadas

- Diagnóstico read-only do incidente: 21 projeções técnicas; 179 mídias históricas sem attachment, sendo 84 inbound e 95 outbound; backlog/falhas do Wazync em zero no momento observado.
- Teste de dry-run de quarentena: 1 candidata interna, zero mutações; execução e reversão foram exercitadas somente no banco isolado de testes.
- Teste de dry-run de mídia: 2 candidatas (1 inbound e 1 outbound), zero outboxes; descriptor terminalmente expirado foi excluído.
- Testes de governança: lote limitado a 2, nova execução foi bloqueada enquanto havia resultado pendente e uma falha transitória só abriu uma nova tentativa após terminalização/backoff.
- Nenhuma quarentena ou recuperação real foi executada como parte deste handoff. Flags permanecem fail-closed (`enabled=false`, `kill_switch=true`) e não existe schedule para essas operações.

Quarentena e egress reais continuam pendentes de autorização operacional explícita, validação do ambiente e ator platform admin informado por ID interno.
