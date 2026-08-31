# CM Comercial — Loja Virtual Enterprise

E-commerce responsivo em PHP 8.2+, HTML5, CSS3 e JavaScript, com catálogo, carrinho, checkout, clientes, administração, estoque, frete, pagamentos e segurança.

## Arquitetura

A camada enterprise usa Clean Architecture pragmática com PSR-4:

- `src/Core/` — infraestrutura de injeção de dependências.
- `src/Config/` — acesso MySQL via PDO e transações ACID.
- `src/Security/` — CSRF e validação HMAC de webhooks.
- `src/Gateways/` — contratos e adapters de gateways.
- `src/Services/` — regras de aplicação e orquestração transacional.
- `database/schema.sql` — modelo MySQL 8/InnoDB.
- `checkout.php` — checkout PIX.
- `api/order_status.php` — status JSON autenticado para polling.
- `webhooks/webhook_handler.php` — entrada de eventos Mercado Pago.
- `assets/css/app.css` — Design System responsivo.
- `assets/js/checkout.js` — UX de PIX, Toast, tema e polling de 4 segundos.

## Instalação de produção

1. PHP 8.2+ com `pdo_mysql`, `curl` e `json`.
2. MySQL 8+.
3. Execute `database/schema.sql` no banco dedicado.
4. Execute `composer install --no-dev --optimize-autoloader` em `CM-Comercial/`.
5. Configure as variáveis de ambiente do `.env.example` no servidor.
6. Publique somente o diretório web necessário e bloqueie `src/`, `config/` e `database/`.
7. Configure HTTPS.
8. Configure o webhook HTTPS do Mercado Pago.
9. Execute `production_preflight.php` e os testes de homologação.

## Segurança

Não commitamos credenciais, banco de produção, logs ou uploads privados. O checkout não confia em valores enviados pelo navegador para cálculo do pedido, e o fluxo de estoque utiliza bloqueio pessimista `FOR UPDATE` antes da reserva.

Nunca armazene credenciais de gateway no código-fonte.
