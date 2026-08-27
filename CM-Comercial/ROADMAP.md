# CM Comercial — Fases e Etapas

## ✅ Concluídas — 34 / 35

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
21. Conexão do Payment Service ao checkout real (`includes/checkout_payment.php`)
22. Adapter de gateway real — Mercado Pago sandbox (`integrations/mercadopago_adapter.php`)
23. Autenticação de webhook conforme o provedor (`webhooks/webhook_handler.php`)
24. Fluxo financeiro completo: autorização/captura/estorno (`integrations/payment_operations.php`)
25. Conciliação financeira e relatórios (`admin/reconciliation.php`)
26. Reserva e liberação de estoque ligada ao pagamento (`includes/stock_payment.php`)
27. Histórico financeiro detalhado na área do cliente (`financial_history.php`)
28. Melhorias de UX/UI e acessibilidade (`assets/ux-enhancements.js`)
29. Testes automatizados de integração e regressão (`tests/integration_test.php`)
30. Testes de segurança e hardening (`tests/security_checklist.php`)
31. Configuração de produção: `.htaccess`, pastas protegidas, `DEPLOY.md`
32. SEO, performance e observabilidade (`sitemap.php`, `robots.txt`, `includes/logger.php`)
33. Teste ponta a ponta do fluxo comercial (`tests/e2e_smoke.php`)
34. Revisão final e release de produção

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
| API de frete/CEP (ViaCEP ou parceiro) | Opcional |
| SMTP/e-mail transacional | Opcional |
| Backup externo configurado no servidor | Aguardando |

Ver checklist completo em `DEPLOY.md`.

## Pacote recebido

O pacote fornecido pelo usuário para as etapas 21–34 foi armazenado em `releases/CM-Comercial-etapas-21-34.zip` e possui workflow de importação para materializar os arquivos no diretório do projeto. A importação usa `[skip cm-import]` para impedir loop de execução.
