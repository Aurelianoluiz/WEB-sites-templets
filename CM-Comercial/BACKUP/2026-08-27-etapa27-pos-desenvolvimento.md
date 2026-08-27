# Ponto de Backup — CM-Comercial

Data: 27/08/2026

## Identificação

Branch de restauração: `backup/CM-Comercial-2026-08-27-etapa27-pos-desenvolvimento`

Este ponto foi criado após a conclusão da integração da área financeira do cliente e dos testes de isolamento do histórico financeiro.

## Estado preservado

- código do `main` no momento da criação da branch;
- Payment Core / Payment Service;
- checkout e adapter Mercado Pago;
- webhook;
- operações financeiras;
- conciliação;
- política estoque × pagamento;
- histórico financeiro do cliente;
- camada autenticada `account_financial.php`;
- teste `customer_financial_history_test.php`;
- documentação e roadmap.

## Restauração

Para restaurar este estado, utilizar a branch acima como referência. Este backup não deve receber alterações de desenvolvimento.

> O horário exato de criação é o timestamp registrado pelo GitHub no commit deste manifesto.
