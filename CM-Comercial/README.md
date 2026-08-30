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
**32/32 fases de desenvolvimento técnico concluídas.** As fases 33–35 estão no fechamento de release: E2E/homologação em ambiente real e criação do pacote ZIP final. O código não deve ser considerado produção-aprovado até que hospedagem, HTTPS, banco e serviços externos estejam configurados e validados.

## Histórico completo
Consulte [`ROADMAP.md`](ROADMAP.md) e [`ETAPAS-FASES.md`](ETAPAS-FASES.md) para o histórico completo das fases, resultados, testes e pendências externas.

## Instalação
1. PHP 8.2+ com PDO SQLite habilitado para a configuração de referência.
2. Configure HTTPS em produção.
3. Configure as variáveis de ambiente.
4. Execute `setup.php` uma única vez em ambiente controlado.
5. Crie o administrador.
6. Remova ou bloqueie `setup.php`.
7. Configure gateway/frete/SMTP quando aplicável.
8. Execute `PRODUCTION-ACTIVATION.md` antes de liberar a loja.

Nunca armazene credenciais de gateway no código-fonte.

## Documentação
- `ROADMAP.md` — estado atual das 35 etapas.
- `ETAPAS-FASES.md` — histórico das fases.
- `RELEASE-CHECKLIST.md` — gates técnicos e externos.
- `FINAL-READINESS.md` — prontidão para produção.
- `PRODUCTION-ACTIVATION.md` — procedimento de ativação.
- `TEST-REPORT.md` — homologação técnica.
- `integrations/README.md` — arquitetura de integrações.
