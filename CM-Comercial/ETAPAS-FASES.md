# CM Comercial — Etapas e Fases do Desenvolvimento

Projeto: loja virtual CM Comercial
Tecnologias: HTML5, CSS3, JavaScript e PHP
Identidade: vermelho, amarelo, preto e branco; logo fornecido pelo cliente.
Referência visual: Home Center / e-commerce de materiais de construção, inspirado na estrutura de grandes home centers, sem copiar identidade proprietária.

## Fases concluídas

| Fase | Etapa | Status |
|---:|---|:---:|
| 1 | Fundação do sistema | CONCLUÍDA |
| 2 | Catálogo e produtos | CONCLUÍDA |
| 3 | Carrinho + Checkout + Pedidos | CONCLUÍDA |
| 4 | Área completa do cliente | CONCLUÍDA |
| 5 | Gestão administrativa de pedidos | CONCLUÍDA |
| 6 | Usuários e permissões | CONCLUÍDA |
| 7 | Estoque e auditoria | CONCLUÍDA |
| 8 | Banners, campanhas e ofertas | CONCLUÍDA |
| 9 | Frete e regras de entrega | CONCLUÍDA |
| 10 | Pagamentos — arquitetura | CONCLUÍDA |
| 11 | Segurança + preparação de produção | CONCLUÍDA |
| 12 | SEO + desempenho + acessibilidade | CONCLUÍDA |
| 13 | Homologação técnica | CONCLUÍDA |
| 14 | Auditoria final + preparação de produção | CONCLUÍDA |
| 15 | Refinamento visual + UX/UI | CONCLUÍDA |
| 16 | Integrações + preparação de deploy | CONCLUÍDA |
| 17 | Homologação técnica final | CONCLUÍDA |

## Fase 1 — Fundação
- PHP, HTML5, CSS3 e JavaScript.
- Estrutura inicial de banco via PDO/SQLite.
- Sessões e autenticação.
- Separação de cliente e administrador.

## Fase 2 — Catálogo e produtos
- Produtos, categorias, busca, detalhes, preços e estoque.
- CRUD administrativo.
- Destaques e ofertas.

## Fase 3 — Carrinho, checkout e pedidos
- Carrinho persistente.
- Checkout.
- Criação de pedidos.
- Itens, subtotal e total.
- Baixa de estoque.

## Fase 4 — Área do cliente
- Cadastro/login/logout.
- Minha conta.
- Endereços.
- Histórico e detalhes de pedidos.

## Fase 5 — Gestão de pedidos
- Dashboard administrativo.
- Consulta e filtros.
- Status de pedido.
- Gestão operacional.

## Fase 6 — Usuários e permissões
- Papéis customer/admin.
- Proteção das rotas administrativas.
- Ativação/bloqueio de usuários.
- Administração de acessos.

## Fase 7 — Estoque e auditoria
- Saldo.
- Entradas/saídas.
- Baixo estoque.
- Histórico de movimentações.

## Fase 8 — Banners/campanhas/ofertas
- CRUD de banners.
- Ordem e ativação.
- CTA.
- Campanhas integradas à Home.

## Fase 9 — Frete e entrega
- Retirada e entrega.
- CEP/endereço.
- Regras por CEP/UF.
- Frete grátis por valor.
- Prazo e modalidade gravados no pedido.

## Fase 10 — Pagamentos
- PIX e cartão como opções arquiteturais.
- Status financeiro separado do status do pedido.
- Não armazenar número de cartão/CVV.
- Estrutura para gateway externo.

## Fase 11 — Segurança
- Sessões/cookies seguros.
- CSRF.
- Headers de segurança.
- Proteção de uploads.
- Proteção de arquivos sensíveis.
- Ambiente de produção.
- `.env.example`.

## Fase 12 — SEO, desempenho e acessibilidade
- Meta tags.
- Canonical/Open Graph.
- Sitemap/robots.
- Dados estruturados.
- Lazy loading/defer.
- Navegação por teclado.
- ARIA/foco/contraste.
- Responsividade.

## Fase 13 — Homologação técnica
- Lint PHP.
- Validação JS.
- Revisão de rotas.
- Revisão de segurança.
- Checklist de testes.

## Fase 14 — Auditoria final
- `.htaccess`.
- Arquivos sensíveis.
- Páginas 403/404/500.
- Deploy checklist.
- Configuração de produção.

## Fase 15 — UX/UI
- Header e busca.
- Menu/sidebar.
- Hero.
- Vitrines.
- Cards.
- Carrinho.
- Mobile.
- Acessibilidade e microinterações.

## Fase 16 — Integrações + deploy
- Adaptador de pagamento.
- Preparação para frete externo.
- Preparação para e-mail.
- Configuração segura de credenciais.
- Documentação de deploy.

## Fase 17 — Homologação final
- Revisão técnica final.
- Validação de estrutura.
- Pacote final.
- Preparação para ambiente real.

## Pendências externas para produção real
Estas não são fases de desenvolvimento do código, mas dependem de serviços/credenciais externos:

1. Hospedagem PHP compatível.
2. Domínio e DNS.
3. HTTPS/SSL.
4. Gateway de pagamento real e credenciais.
5. API de frete/CEP, se desejada.
6. SMTP/e-mail transacional, se desejado.
7. Banco/driver de produção habilitado.
8. Teste de compra em ambiente real/sandbox.
9. Configuração de backup.

## Estado
Desenvolvimento técnico: 17/17 fases concluídas.
Deploy real: depende das credenciais e infraestrutura de produção.
