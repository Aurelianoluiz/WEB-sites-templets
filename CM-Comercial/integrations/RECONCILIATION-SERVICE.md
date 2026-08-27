# Reconciliation Service

`reconciliation_service.php` compara o estado interno do pagamento com uma resposta normalizada do gateway.

## Verificações

- status;
- valor com precisão de centavos;
- transaction_id quando os dois lados possuem o identificador.

A função não altera pagamentos automaticamente. Divergências são retornadas para revisão administrativa, evitando estorno, captura ou alteração de pedido causada apenas por uma rotina de conciliação.
