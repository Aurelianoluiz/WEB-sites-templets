# Stock × Payment Policy

A reserva de estoque agora possui uma política explícita por estado financeiro.

| Pagamento | Ação de estoque |
|---|---|
| pending | manter reserva |
| authorized | manter reserva |
| paid | confirmar/consumir reserva |
| failed | liberar reserva |
| cancelled | liberar reserva |
| refunded | revisão de estoque |

## Idempotência

Cada operação recebe uma chave determinística baseada no pedido e na ação. O executor de estoque deve persistir essa chave com unicidade antes de efetuar uma mutação, evitando baixa ou liberação duplicada.

O estado `refunded` exige revisão porque o produto pode já ter sido enviado, entregue ou devolvido; não é seguro devolver quantidade ao estoque automaticamente em todos os cenários.
