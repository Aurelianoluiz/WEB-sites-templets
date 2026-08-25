# Payment Service

`integrations/payment_service.php` é a ponte entre o checkout e o núcleo financeiro.

## Responsabilidades

1. Criar/recuperar o registro interno de pagamento para um pedido.
2. Validar pedido, valor e método antes de persistir.
3. Receber eventos já normalizados e autenticados por um adapter.
4. Processar cada `event_id` somente uma vez.
5. Atualizar o status financeiro dentro da mesma transação do ledger de eventos.
6. Reverter tudo quando uma transição for inválida.

## Importante

O serviço não recebe credenciais de gateway e não confia em status enviados pelo navegador. A integração externa continua sendo responsabilidade de um `PaymentGatewayAdapter` específico.
