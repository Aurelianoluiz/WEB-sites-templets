# Testes automatizados

## Executar

No ambiente com PHP CLI:

```bash
php tests/payment_core_test.php
```

O teste valida as transições permitidas e proibidas do Payment Core sem tocar no banco de dados.

## Próximos testes

- idempotência de webhook;
- criação/atualização de payment;
- integração pedido x pagamento;
- rollback transacional;
- estoque após cancelamento/estorno;
- autorização de acesso administrativo;
- checkout ponta a ponta.
