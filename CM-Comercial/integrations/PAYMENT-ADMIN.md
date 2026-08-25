# Monitoramento administrativo de pagamentos

Foi adicionada a rota `admin/payments.php` para consulta dos pagamentos internos.

## Regras

- Acesso somente com `require_admin()`.
- A tela é somente de consulta; não há botão para marcar pagamento como pago.
- Filtros disponíveis por estado financeiro.
- Exibe método, valor, transação, pedido e última atualização.
- O gateway exibido vem da configuração do servidor.

A confirmação financeira continuará vindo de um adapter/gateway autenticado, não de uma alteração manual no navegador.
