# CM Comercial — Release Manifest

Release: Final v2
Data: 2026-08-25

## Validação local
- PHP 8.4.23: `php -l` em todos os arquivos PHP — OK.
- Node.js 22.16.0: `node --check js/app.js` — OK.
- Pacote ZIP: 1.9 MB.
- Arquivos no pacote: 55.
- SHA-256 do pacote `cm-comercial-final-completo-v2.zip`: `ec9c84a7888f1b5b110456f65d78c4c34eb1063443990e675d344c86c67471d8`.

## Conteúdo principal
- Aplicação PHP/HTML5/CSS3/JavaScript.
- Área de cliente e área administrativa separadas.
- Catálogo, categorias, busca e produto.
- Carrinho, checkout e pedidos.
- Estoque e auditoria.
- Frete/regras de entrega.
- Arquitetura de pagamento externo.
- Segurança, SEO, acessibilidade e responsividade.
- Logo em `assets/logo.png`.
- Documentação completa das 17 fases.
- Checklist de produção e relatório de testes.

## Estado de produção
O desenvolvimento técnico está concluído. A publicação comercial ainda depende de infraestrutura e credenciais externas: hospedagem PHP, domínio/DNS, HTTPS, gateway de pagamento, serviço de frete/CEP, SMTP e configuração de backup. Esses itens não são marcados como concluídos sem teste real.

## Fases
1 a 17 — concluídas tecnicamente. Consulte `ETAPAS-FASES.md`.
