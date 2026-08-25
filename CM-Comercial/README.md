# CM Comercial — Loja Virtual

E-commerce responsivo em PHP, HTML5, CSS3 e JavaScript, com catálogo, carrinho, checkout, clientes, administração, estoque, frete, pagamentos e segurança.

## Identidade visual
- Vermelho, amarelo, preto e branco.
- Logo CM Comercial fornecido para o projeto.
- Experiência inspirada na organização de grandes Home Centers, com identidade própria.

## Perfis
- **Cliente/usuário:** cadastro, login, catálogo, categorias, busca, produto, carrinho, checkout, endereços, conta e pedidos.
- **Administrador:** dashboard, produtos, categorias, estoque, pedidos, usuários, banners, frete, pagamentos e configurações.

## Status
**17/17 fases de desenvolvimento técnico concluídas.** O projeto está preparado para deploy. A execução de compra real em produção depende de hospedagem, domínio, HTTPS e credenciais dos serviços externos.

## Histórico completo
Consulte [`ETAPAS-FASES.md`](ETAPAS-FASES.md) para todas as 17 fases, resultados, status e pendências externas.

## Instalação
1. PHP com PDO SQLite habilitado.
2. HTTPS em produção.
3. Configure as variáveis de ambiente.
4. Execute `setup.php` uma única vez.
5. Crie o administrador.
6. Remova ou bloqueie `setup.php`.
7. Configure gateway/frete/SMTP quando aplicável.

Nunca armazene credenciais de gateway no código-fonte.

## Documentação
- `ETAPAS-FASES.md` — histórico completo do projeto.
- `PRODUCTION-CHECKLIST.md` — checklist de produção.
- `TEST-REPORT.md` — homologação técnica.
- `integrations/README.md` — arquitetura de integrações.
