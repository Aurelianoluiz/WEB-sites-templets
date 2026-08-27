# CM Comercial — Fases e Etapas

## ✅ Implementação concluída — 34 / 35

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
21. Conexão do Payment Service ao checkout (`includes/checkout_payment.php`) — pacote entregue, validação do projeto-base pendente
22. Adapter Mercado Pago sandbox/produção (`integrations/mercadopago_adapter.php`) — pacote entregue, credenciais não configuradas
23. Webhook com autenticação HMAC (`webhooks/webhook_handler.php`) — pacote entregue, validação com provedor pendente
24. Operações financeiras (`integrations/payment_operations.php`) — pacote entregue
25. Conciliação financeira (`admin/reconciliation.php`) — pacote entregue
26. Reserva/liberação de estoque (`includes/stock_payment.php`) — pacote entregue
27. Histórico financeiro (`financial_history.php`) — pacote entregue
28. UX/UI e acessibilidade (`assets/ux-enhancements.js`) — pacote entregue
29. Testes de integração/regressão — suíte de Payment Core/Service criada; execução no ambiente completo pendente
30. Segurança/hardening — implementação entregue; auditoria final pendente
31. Hardening/deploy — `.htaccess` e `DEPLOY.md` no pacote; homologação do servidor pendente
32. SEO/observabilidade — pacote entregue; validação em hospedagem pendente
33. Teste ponta a ponta — script entregue; execução no ambiente completo pendente
34. Revisão final/release — documentação e estrutura preparadas; homologação final pendente

## 🟡 Validação pendente

As etapas 21–34 foram entregues como pacote e/ou implementação no repositório, mas não devem ser consideradas homologadas em produção até executar os testes sobre uma cópia completa da aplicação com PHP/PDO SQLite e, para pagamentos, sandbox do provedor.

O runner local de testes é `tests/run_tests.php` e executa as suítes financeiras disponíveis.

## ⏳ Pendente — 1 / 35

35. **Backup final + ZIP** — somente quando solicitado explicitamente.

## Regra de backup

Backups intermediários não são criados automaticamente.
Um novo ponto de backup só deve ser criado quando o usuário solicitar explicitamente.

## Pendências externas para produção real

Estas não são etapas de código — dependem de credenciais e infraestrutura:

| Item | Status |
|------|--------|
| Hospedagem PHP 8.1+ | Aguardando |
| Domínio e DNS | Aguardando |
| Certificado HTTPS/SSL | Aguardando |
| Credenciais do gateway (Mercado Pago) | Aguardando |
| API de frete/CEP | Opcional |
| SMTP/e-mail transacional | Opcional |
| Backup externo no servidor | Aguardando |

Ver checklist completo em `DEPLOY.md`.

## Pacote recebido

O pacote fornecido pelo usuário para as etapas 21–34 foi armazenado em `releases/CM-Comercial-etapas-21-34.zip` e possui workflow de importação para materializar os arquivos no diretório do projeto.
