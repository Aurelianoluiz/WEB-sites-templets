# Etapa 34 — Release Checklist

## Automação já existente

- [x] Pipeline de CI configurada para PHP 8.2, 8.3 e 8.4.
- [x] `php -l` automatizado para todos os arquivos PHP.
- [x] `validation_runner.php` executado automaticamente pela CI.
- [x] `release_consistency.php` executado automaticamente pela CI.
- [x] `release_gate.php` executado automaticamente pela CI.

## Gates dependentes de ambiente

- [ ] CI executada com sucesso no GitHub Actions e evidência registrada.
- [ ] Testes de integração executados em ambiente com banco configurado.
- [ ] E2E executado em ambiente de homologação.
- [ ] Nenhum segredo versionado.

## Segurança

- [ ] HTTPS ativo.
- [ ] Cookies de sessão com Secure/HttpOnly/SameSite adequados.
- [ ] CSRF aplicado a operações autenticadas que alteram estado.
- [ ] Autorização administrativa validada no servidor.
- [ ] Webhook validado por assinatura/segredo, freshness e processamento idempotente.

## Pagamentos e estoque

- [ ] Mercado Pago em credenciais do ambiente correto.
- [ ] Webhook configurado para HTTPS.
- [ ] Estados de pagamento testados em ambiente real/sandbox.
- [ ] Reserva/liberação de estoque testada contra duplicidade.
- [ ] Operações de estoque pendentes reconciliadas e revisadas.
- [ ] Conciliação financeira revisada.

## Operação

- [ ] Logs fora do webroot.
- [ ] Monitoramento e alertas configurados.
- [ ] Backup do banco externo ao servidor web.
- [ ] Política de retenção definida.
- [ ] Restauração do backup validada.

A release somente deve ser marcada como pronta após todos os itens aplicáveis serem validados no ambiente de homologação/produção. Os itens marcados como `[x]` representam apenas automações presentes no repositório; não substituem a evidência de execução em ambiente real.
