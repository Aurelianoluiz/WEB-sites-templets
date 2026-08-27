# CM Comercial — Release Status

**Data:** 27/08/2026
**Branch:** `main`
**Commit de referência:** `d1b65743db1e325499341f50d7f36e07492d7a17`

## Concluído no código

- Catálogo, categorias, busca, produto e carrinho.
- Checkout e pedidos.
- Cliente e administrador com responsabilidades separadas.
- Estoque e auditoria.
- Payment Core e Payment Service.
- Adapter Mercado Pago.
- Webhook com verificação por segredo/assinatura.
- Captura/estorno no domínio interno.
- Conciliação.
- Política de estoque por evento financeiro.
- Histórico financeiro do cliente.
- UX responsiva e acessibilidade.
- Hardening/documentação de deploy.
- SEO e observabilidade.
- Testes de domínio, integração e security audit versionados.
- CI configurada para PHP 8.2, 8.3 e 8.4.

## Validações disponíveis

A validação de sintaxe anterior registrou 12/12 arquivos PHP do pacote 21–34 sem erros de sintaxe. O projeto também possui uma suíte de integração e um release gate para verificar artefatos de validação.

## Não declarar produção aprovada ainda

Aprovação final depende de execução em ambiente configurado:

1. CI realmente executada e registrada.
2. Banco configurado.
3. E2E executado no servidor de homologação.
4. Mercado Pago Sandbox validado.
5. Webhook HTTPS validado.
6. Captura e estorno testados em sandbox.
7. Headers, cookies, permissões e logs verificados em servidor real.

## Etapa seguinte

Após os gates acima, gerar o ponto de restauração final e o ZIP completo de distribuição. O backup final só deve ser criado quando solicitado explicitamente.
