# Payment Core

Implementado o núcleo financeiro interno da CM Comercial.

## Estados

`pending → authorized → paid → refunded`

Estados de falha/cancelamento são terminais quando aplicável.

## Princípios

- O status financeiro é separado do status logístico do pedido.
- Eventos externos usam `event_id` idempotente.
- `payment_events` impede processamento duplicado do mesmo evento.
- Gateway real deve ser conectado por adapter, sem armazenar credenciais no código.
- `transaction_id` identifica a transação externa quando fornecida.

## Arquivo

`includes/payment_core.php`

Este núcleo ainda não representa uma integração com um gateway real. A conexão real deverá ser feita em uma etapa posterior, com credenciais e ambiente sandbox/produção configurados.
