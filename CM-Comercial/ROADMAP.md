# CM Comercial — Fases e Etapas

## Concluídas

1. Arquitetura base HTML5/CSS/JS/PHP
2. Layout responsivo e identidade visual
3. Catálogo/produtos
4. Carrinho
5. Sidebar/menu por categorias
6. Separação de acesso cliente x administrador
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
19. Política de consistência pagamento x pedido
20. Documentação técnica e registro das alterações no GitHub

## Em aberto

21. Conectar o Payment Service ao checkout real existente
22. Implementar adapter de gateway real em sandbox
23. Autenticação de webhook conforme o provedor escolhido
24. Fluxo financeiro completo: autorização/captura/estorno
25. Conciliação financeira e relatórios
26. Reserva/liberação de estoque ligada ao pagamento
27. Histórico financeiro detalhado na área do cliente
28. Melhorias de UX/UI e acessibilidade
29. Testes automatizados de integração e regressão
30. Testes de segurança e hardening de produção
31. Configuração de produção: ambiente, HTTPS, banco, cron/logs
32. SEO, performance e observabilidade
33. Teste ponta a ponta do fluxo comercial
34. Revisão final e release de produção
35. Backup final solicitado pelo usuário e empacotamento ZIP

## Regra de backup

Backups intermediários não são criados automaticamente. Um novo ponto de backup só deve ser criado quando o usuário solicitar explicitamente.
