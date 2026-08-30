# CM Comercial — Fases e Etapas

## ✅ Desenvolvimento técnico concluído — 32 / 35

1. Arquitetura base HTML5/CSS/JS/PHP
2. Layout responsivo e identidade visual
3. Catálogo/produtos
4. Carrinho
5. Sidebar/menu por categorias
6. Separação de acesso cliente × administrador
7. Autenticação e sessão
8. Área administrativa
9. Checkout e validações defensivas
10. Controle/auditoria de estoque
11. Cancelamento de pedidos pelo cliente
12. Controle de transições de pedidos no admin
13. Histórico/auditoria de status de pedidos
14. Núcleo financeiro (Payment Core)
15. Ledger idempotente de eventos de pagamento
16. Contrato de gateway (Payment Adapter)
17. Payment Service
18. Painel administrativo financeiro
19. Política de consistência pagamento × pedido
20. Documentação técnica e registro no GitHub
21. Conexão do Payment Service ao checkout (`includes/checkout_payment.php`)
22. Adapter Mercado Pago sandbox/produção (`integrations/mercadopago_adapter.php`)
23. Webhook com autenticação HMAC (`webhooks/webhook_handler.php`)
24. Operações financeiras (`integrations/payment_operations.php`)
25. Conciliação financeira (`admin/reconciliation.php`)
26. Reserva/liberação de estoque (`includes/stock_payment.php`)
27. Histórico financeiro (`financial_history.php`)
28. UX/UI e acessibilidade (`assets/ux-enhancements.js`)
29. Testes de integração/regressão
30. Segurança/hardening
31. Hardening/deploy
32. SEO/observabilidade

## 🟡 Fases de validação / fechamento

33. **Teste ponta a ponta** — script e cobertura implementados; execução final depende de ambiente de homologação.
34. **Revisão final/release** — Release Gate, checklist e documentação preparados; homologação final depende dos gates externos.
35. **Backup final + ZIP** — backup versionado já possui pontos de restauração no Git; o ZIP físico final da versão homologada ainda precisa ser gerado.

## Regra de evidência

As fases 21–34 podem possuir implementação e testes versionados, mas não devem ser declaradas como homologadas em produção sem evidência dos gates dependentes de ambiente.

O Release Gate determinístico valida os artefatos locais; os gates externos continuam separados para não produzir falso positivo de produção.

## Regra de backup

Backups intermediários não são criados automaticamente.
Um novo ponto de backup só deve ser criado quando o usuário solicitar explicitamente.

## Pendências externas para produção real

| Item | Status |
|------|--------|
| CI GitHub Actions executada com sucesso | Aguardando evidência |
| Banco de dados configurado | Aguardando |
| Hospedagem PHP 8.1+ | Aguardando |
| Domínio e DNS | Aguardando |
| Certificado HTTPS/SSL | Aguardando |
| Credenciais do gateway (Mercado Pago) | Aguardando |
| Webhook HTTPS autenticado | Aguardando |
| E2E em homologação | Aguardando |
| Backup externo do banco | Aguardando |
| API de frete/CEP | Opcional |
| SMTP/e-mail transacional | Opcional |

Ver checklist completo em `RELEASE-CHECKLIST.md` e `DEPLOY.md`.

## Últimas correções de robustez

- Validação de eventos de pagamento na fronteira do domínio.
- Idempotência e ownership de eventos.
- Proteção contra replay de webhook e janela de freshness.
- Integridade de `transaction_id` / `refund_transaction_id`.
- Atomicidade entre pagamento e atualização downstream.
- Reconciliação de operações de estoque.
- Inventário centralizado do Release Gate.
- Validação da superfície de configuração do Mercado Pago.
- Validação direta de `payment_event_id` / `event_type` no ledger.
