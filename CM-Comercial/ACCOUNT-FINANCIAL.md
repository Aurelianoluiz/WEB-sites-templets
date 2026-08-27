# Minha Conta — Histórico Financeiro

O cliente consulta apenas os próprios pagamentos por meio da sessão autenticada.

- `account_financial.php` é a camada de acesso da conta;
- `financial_history.php` é o read model;
- `customer_id` não deve ser recebido por query string para esta funcionalidade;
- credenciais e payloads brutos do gateway não são exibidos;
- a paginação limita a quantidade retornada.

O painel administrativo continua sendo o local para conciliação e gerenciamento financeiro operacional.
