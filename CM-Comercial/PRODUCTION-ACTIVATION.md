# CM Comercial — Procedimento de Ativação em Produção

Este documento fecha o trabalho de código e define as ações externas necessárias para colocar a loja em uso real sem transformar uma validação local em falsa aprovação de produção.

## 1. Servidor

- PHP 8.2+.
- PDO SQLite habilitado para a configuração de referência.
- HTTPS obrigatório.
- Diretório de dados/logs fora do webroot quando aplicável.
- Permissões mínimas necessárias para o processo PHP.

Antes de liberar a aplicação, execute no servidor:

`php production_preflight.php`

O comando deve terminar com `PRODUCTION_PREFLIGHT_PASS`.

## 2. Configuração

Copie `.env.example` para a configuração segura do ambiente e forneça:

- `APP_ENV=production`
- `APP_URL=https://...`
- `PAYMENT_GATEWAY=manual` ou `mercadopago`
- `MP_ACCESS_TOKEN` quando usar Mercado Pago
- `MP_WEBHOOK_SECRET` quando usar Mercado Pago
- `MP_WEBHOOK_MAX_SKEW` (padrão: 300)

Nunca publique esses valores no Git.

## 3. Inicialização

1. Coloque os arquivos no servidor.
2. Execute `setup.php` uma única vez em ambiente controlado, caso a instalação ainda não possua banco.
3. Crie o administrador.
4. Remova ou bloqueie `setup.php` depois da instalação.
5. Confirme o banco e os diretórios de dados.
6. Execute `php production_preflight.php` novamente.

## 4. Pagamentos

Em sandbox primeiro:

1. Criar pagamento PIX.
2. Criar pagamento por cartão tokenizado.
3. Confirmar pagamento aprovado.
4. Confirmar pagamento recusado/cancelado.
5. Confirmar webhook assinado.
6. Reenviar o mesmo webhook e confirmar idempotência.
7. Executar estorno e confirmar `refund_transaction_id` separado do `transaction_id`.

## 5. Estoque

- Confirmar reserva inicial.
- Confirmar commit da reserva após pagamento pago.
- Confirmar liberação após falha/cancelamento.
- Confirmar que operações duplicadas não executam o efeito novamente.
- Revisar operações pendentes no ledger antes de produção.

A mutação física de estoque permanece injetável porque o repositório não fornece uma implementação de inventário universal para substituir essa dependência com segurança.

## 6. E2E

Executar, no servidor de homologação:

`cliente → login → produto → carrinho → checkout → pagamento → webhook → pedido → estoque → histórico`

e também:

`admin → pedido → financeiro → conciliação → revisão de estoque`

## 7. Fechamento

A release só pode ser marcada como produção-aprovada depois de obter evidência de:

- CI concluída com sucesso;
- `production_preflight.php` aprovado no servidor;
- HTTPS ativo;
- banco operacional;
- Mercado Pago sandbox validado;
- webhook HTTPS autenticado;
- E2E aprovado;
- backup externo do banco realizado;
- teste de restauração aprovado.

O ponto de restauração Git pode ser usado para retornar o código, mas não substitui o backup do banco de produção.
