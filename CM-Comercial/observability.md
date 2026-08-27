# Etapa 32 — SEO e Observabilidade

## SEO

- `sitemap.php` expõe URLs públicas que podem ser indexadas;
- `robots.txt` orienta crawlers;
- páginas administrativas e de conta não devem ser indexadas;
- títulos e meta descriptions devem ser únicos por página;
- imagens devem possuir `alt` descritivo quando relevantes.

## Observabilidade

O logger estruturado deve registrar eventos operacionais sem senhas, tokens, dados de cartão ou payloads financeiros sensíveis.

Eventos recomendados:

- `order.created`
- `payment.created`
- `payment.status_changed`
- `payment.webhook_received`
- `stock.reservation_created`
- `stock.reservation_released`
- `reconciliation.mismatch`
- `auth.login_failed`

Em produção, logs devem ser armazenados fora do webroot e possuir retenção definida.
