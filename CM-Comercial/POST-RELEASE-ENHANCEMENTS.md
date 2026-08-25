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

### 5. Cancelamento seguro de pedidos
- Cliente pode cancelar somente pedidos `pending` ou `confirmed`.
- Cancelamento é bloqueado quando `payment_status=paid`.
- A operação exige CSRF e revalidação do pedido no banco.
- O estoque dos itens é estornado dentro da mesma transação.
- Cada estorno gera `stock_movements` com o motivo e o usuário.
- Pedidos de outros usuários não podem ser alterados.

### 6. Fluxo administrativo de pedidos
- O administrador não pode mover um pedido para um status anterior do fluxo.
- Pedido `cancelled` não pode retornar ao fluxo normal.
- Pedido `delivered` não pode ser cancelado pelo painel.
- Cancelamento administrativo também estorna o estoque dentro da mesma transação.
- Cada estorno administrativo gera auditoria em `stock_movements`.
- Falhas de atualização fazem rollback e nenhuma alteração parcial é mantida.

## Validação
- 33 arquivos PHP validados com `php -l`: OK.
- `js/app.js` validado com `node --check`: OK.
- O fluxo real com SQLite continua dependente do driver `pdo_sqlite` no servidor.

## Política de backup
Conforme orientação do responsável pelo projeto, **novos pontos de backup só serão criados quando solicitados explicitamente**.

## Rollback
O ponto anterior continua disponível em `CM-Comercial-BACKUP-2026-08-25_14-22-19.zip`.
