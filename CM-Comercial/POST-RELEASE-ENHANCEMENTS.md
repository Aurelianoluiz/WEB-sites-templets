# CM Comercial — Continuação da Programação

**Data:** 25/08/2026

Esta atualização continua o desenvolvimento a partir do ponto de restauração `CM-Comercial-BACKUP-2026-08-25_14-22-19.zip`.

## Melhorias implementadas

### 1. Configuração de ambiente
- `config.php` aceita variáveis de ambiente.
- Um `.env` local pode ser usado quando a variável ainda não existe no processo.
- Variáveis já definidas no servidor têm prioridade.

### 2. Segurança da conta
- Área do cliente recebeu alteração de senha.
- A senha atual é validada antes da troca.
- A nova senha exige no mínimo 8 caracteres e confirmação.
- A sessão é regenerada após alteração de senha.

### 3. Checkout e estoque
- Limite defensivo de itens recebidos no checkout.
- Preço e estoque são sempre validados no servidor.
- A saída de estoque de uma venda é registrada em `stock_movements`.
- O movimento registra o pedido e o usuário responsável.
- A criação do pedido, baixa e auditoria permanecem dentro da mesma transação.

### 4. API de frete
- A modalidade solicitada precisa estar ativa.
- Payloads excessivos são rejeitados.
- Cotação usa os valores atuais do banco, não os preços enviados pelo navegador.

## Validação
- 33 arquivos PHP validados com `php -l`: OK.
- `js/app.js` validado com `node --check`: OK.
- O fluxo real com SQLite continua dependente do driver `pdo_sqlite` no servidor.

## Rollback
O ponto anterior continua disponível em `CM-Comercial-BACKUP-2026-08-25_14-22-19.zip`.
