# Payment Gateway Adapter

A CM Comercial agora possui uma fronteira explícita entre o núcleo financeiro e gateways externos.

## Contrato

`integrations/payment_adapter.php` define `PaymentGatewayAdapter` com três operações:

- `createPayment()` para iniciar uma cobrança;
- `parseWebhook()` para normalizar eventos recebidos;
- `queryPayment()` para consultar uma transação.

## Segurança

- O gateway é selecionado exclusivamente por configuração do servidor (`PAYMENT_GATEWAY`).
- Credenciais nunca devem ser recebidas do navegador nem gravadas no repositório.
- Webhooks devem ser autenticados pelo adapter do provedor antes de chamar o núcleo financeiro.
- O `event_id` normalizado deve ser enviado ao ledger idempotente de `payment_core.php`.

## Estado atual

O contrato está pronto, mas nenhum gateway real foi conectado ainda. Isso evita simular pagamentos e permite adicionar Mercado Pago, PagSeguro, Stripe ou outro provedor posteriormente sem acoplar o checkout ao fornecedor.
