# Etapa 34 — Release Checklist

## Código

- [ ] PHP syntax check (`php -l`) em todos os arquivos PHP.
- [ ] Testes de integração executados em ambiente com banco configurado.
- [ ] E2E executado em ambiente de homologação.
- [ ] Nenhum segredo versionado.

## Segurança

- [ ] HTTPS ativo.
- [ ] Cookies de sessão com Secure/HttpOnly/SameSite adequados.
- [ ] CSRF aplicado a operações autenticadas que alteram estado.
- [ ] Autorização administrativa validada no servidor.
- [ ] Webhook validado por assinatura/segredo e com processamento idempotente.

## Pagamentos e estoque

- [ ] Mercado Pago em credenciais do ambiente correto.
- [ ] Webhook configurado para HTTPS.
- [ ] Estados de pagamento testados.
- [ ] Reserva/liberação de estoque testada contra duplicidade.
- [ ] Conciliação revisada.

## Operação

- [ ] Logs fora do webroot.
- [ ] Monitoramento e alertas configurados.
- [ ] Backup do banco externo ao servidor web.
- [ ] Política de retenção definida.

A release somente deve ser marcada como pronta após todos os itens aplicáveis serem validados no ambiente de homologação/produção.
