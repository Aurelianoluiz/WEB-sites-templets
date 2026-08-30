# CM Comercial — Final Readiness

Data: 2026-08-30

## Desenvolvimento técnico

- 32/32 fases de desenvolvimento técnico concluídas.
- Fluxo de loja, cliente/admin, estoque, pagamentos, webhooks, segurança, auditoria e Release Gate versionados.
- Suíte determinística de validação atualizada com as regressões de pagamento, webhook, estoque, configuração e adapter Mercado Pago.
- `production_preflight.php` criado para validar requisitos mínimos do servidor antes da ativação.
- Workflow de empacotamento criado em `.github/workflows/cm-comercial-package.yml` para gerar ZIP + SHA-256 da versão, sem `.env`, bancos, logs, uploads privados ou backups.

## Fechamento da release

### Concluído no código/repositório

- Fluxos E2E e regressões necessários preparados.
- Release Gate com inventário centralizado e consistência verificável.
- Documentação alinhada ao estado real.
- Backup Git com pontos de restauração versionados.
- Preflight de produção para PHP, extensões, HTTPS/URL e configuração do gateway.
- Empacotamento seguro da release automatizado pelo GitHub Actions.

### Ainda depende de infraestrutura externa

- Executar CI GitHub Actions e registrar evidência do resultado.
- Configurar banco no servidor de homologação/produção.
- Configurar hospedagem PHP 8.2+.
- Ativar HTTPS.
- Configurar `MP_ACCESS_TOKEN` e `MP_WEBHOOK_SECRET` no ambiente, quando Mercado Pago estiver ativo.
- Configurar webhook HTTPS no Mercado Pago.
- Executar `php production_preflight.php` no servidor e obter `PRODUCTION_PREFLIGHT_PASS`.
- Executar E2E real com sandbox.
- Testar captura, cancelamento e estorno reais.
- Configurar logs, monitoramento e retenção.
- Fazer backup externo do banco e validar restauração.
- Executar o workflow de empacotamento e validar o SHA-256 do ZIP gerado.

## Status

**Release Candidate tecnicamente preparado — ativação externa ainda não realizada.**

A aplicação só deve ser liberada depois que os gates externos forem executados e registrados. O preflight ajuda a detectar configuração incorreta antes do tráfego real, mas não substitui E2E, validação do gateway ou backup/restauração.
