# CM Comercial — Etapas e Fases do Desenvolvimento

Projeto: loja virtual CM Comercial
Tecnologias: HTML5, CSS3, JavaScript e PHP
Identidade: vermelho, amarelo, preto e branco; logo fornecido pelo cliente.
Referência visual: Home Center / e-commerce de materiais de construção, inspirado na estrutura de grandes home centers, sem copiar identidade proprietária.

## Fases concluídas — desenvolvimento estrutural

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

## Fases 18–34 — endurecimento, validação e release

| Fase | Etapa | Status atual |
|---:|---|:---:|
| 18 | Baseline de release e inventário técnico | CONCLUÍDA |
| 19 | Revisão de autenticação, sessão e autorização | CONCLUÍDA |
| 20 | Revisão de CSRF e proteção de operações de estado | CONCLUÍDA |
| 21 | Checkout conectado ao serviço de pagamento | CONCLUÍDA |
| 22 | Adapter Mercado Pago / sandbox-produção | CONCLUÍDA |
| 23 | Webhook autenticado por HMAC-SHA256 | CONCLUÍDA |
| 24 | Operações de autorização/captura/estorno | CONCLUÍDA |
| 25 | Conciliação financeira e divergências | CONCLUÍDA |
| 26 | Política de reserva/liberação de estoque | CONCLUÍDA |
| 27 | Histórico financeiro do cliente | CONCLUÍDA |
| 28 | UX, acessibilidade e formatação de campos | CONCLUÍDA |
| 29 | Testes automatizados de integração | CONCLUÍDA |
| 30 | Auditoria e endurecimento de segurança | CONCLUÍDA |
| 31 | Hardening e preparação de deploy | CONCLUÍDA |
| 32 | SEO + observabilidade + logs | CONCLUÍDA |
| 33 | E2E smoke e especificação de fluxo | IMPLEMENTAÇÃO CONCLUÍDA / EXECUÇÃO EXTERNA PENDENTE |
| 34 | Release Gate / homologação final | ESTRUTURA CONCLUÍDA / HOMOLOGAÇÃO EXTERNA PENDENTE |

## Correções e validações adicionadas após a Fase 17

### Segurança e autenticação
- Helper centralizado de CSRF em `includes/csrf.php`.
- Compatibilidade de `verify_csrf()` com módulos administrativos existentes.
- Proteção reforçada de sessão em `config.php`.
- Logout por POST com CSRF e destruição completa da sessão.
- Testes de sessão, autenticação, autorização, logout, CSRF e isolamento do cliente.
- Identidade financeira vinculada exclusivamente a `$_SESSION['user']['id']`.
- Auditoria de armazenamento/verificação segura de senhas.

### Pagamentos
- Payload bruto do gateway não é devolvido diretamente pelo checkout.
- Webhook validado por assinatura HMAC.
- Valor do gateway comparado ao valor interno antes da mudança de estado.
- Idempotência de eventos de pagamento por `event_id`.
- Máquina de estados para pagamentos e pedidos.
- Testes de consistência de pagamento, operações, política de pedido e estorno.

### Estoque
- Política: `paid → commit_reservation`.
- Política: `failed/cancelled → release_reservation`.
- `refunded → review_refund_stock`, sem baixa automática.
- Ledger `stock_payment_operations` para impedir duplicação de efeitos.
- Bridge de pagamento para a camada específica de estoque, com mutação real injetada para evitar assumir um schema inexistente.

### Release / testes
- `validation_runner.php` para testes determinísticos.
- `release_consistency.php` para manter runner e gate sincronizados.
- `release_gate.php` para inventário obrigatório.
- Testes específicos de pagamento, segurança, identidade, estoque e estorno.
- CI preparada para PHP 8.2, 8.3 e 8.4.

## Fases ainda abertas / dependências externas

### 33 — E2E
A especificação está versionada, porém a execução final depende de servidor acessível, banco configurado, sessão real, checkout e gateway em sandbox.

### 34 — Homologação / Release
A estrutura técnica está pronta, mas a homologação externa ainda precisa comprovar:

1. Banco de dados/driver de produção.
2. HTTPS real.
3. Mercado Pago Sandbox configurado.
4. Webhook HTTPS funcional.
5. Captura e estorno em sandbox.
6. Execução E2E em servidor.
7. Logs e retenção configurados.
8. Backup externo do banco.

### 35 — Backup final + pacote ZIP
Pendente de solicitação/execução final. Quando realizado, deve registrar no próprio repositório a data, hora, commit de referência e integridade do pacote.

## Estado consolidado

- Desenvolvimento técnico estrutural: 17/17 fases concluídas.
- Fases 18–32: implementação e validação técnica concluídas.
- Fase 33: código/spec concluídos; execução E2E final depende de ambiente.
- Fase 34: release gate e documentação consolidados; homologação externa pendente.
- Fase 35: backup final e ZIP ainda não realizados nesta rodada.

**Regra de backup:** nenhum novo backup deve ser criado automaticamente; somente quando explicitamente solicitado.
