# CM Comercial — Final Readiness

Data: 2026-08-30

## Desenvolvimento técnico

- 32/32 fases de desenvolvimento técnico concluídas.
- Fluxo de loja, cliente/admin, estoque, pagamentos, webhooks, segurança, auditoria e Release Gate versionados.
- Suíte determinística de validação atualizada com as regressões de pagamento, webhook, estoque, configuração e adapter Mercado Pago.

## Fechamento da release

### Concluído no código/repositório

- E2E: fluxos e regressões necessários estão preparados no projeto.
- Release Gate: inventário centralizado e consistência entre runner/gate verificável.
- Documentação: roadmap, checklist, readiness e procedimento de ativação alinhados ao estado real.
- Backup Git: existem pontos de restauração versionados.

### Ainda depende de infraestrutura externa

- Executar CI GitHub Actions e registrar evidência do resultado.
- Configurar banco no servidor de homologação/produção.
- Configurar hospedagem PHP 8.2+.
- Ativar HTTPS.
- Configurar `MP_ACCESS_TOKEN` e `MP_WEBHOOK_SECRET` no ambiente.
- Configurar webhook HTTPS no Mercado Pago.
- Executar E2E real com sandbox.
- Testar captura, cancelamento e estorno reais.
- Configurar logs, monitoramento e retenção.
- Fazer backup externo do banco e validar restauração.

## Status

**Release Candidate tecnicamente preparado — ativação externa ainda não realizada.**

Não é seguro declarar produção-aprovada somente com testes do repositório. A ativação depende das credenciais e da infraestrutura descritas em `PRODUCTION-ACTIVATION.md`.
