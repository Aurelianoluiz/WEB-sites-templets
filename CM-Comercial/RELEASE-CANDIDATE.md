# CM Comercial — Release Candidate

Data: 2026-08-27

## Estado

O desenvolvimento técnico registrado no projeto está concluído. A release ainda depende de validações que exigem ambiente de execução e serviços externos.

## Gates internos

- Arquitetura e módulos versionados: OK
- Separação cliente/admin: OK
- Fluxo financeiro e idempotência: OK
- Documentação de segurança/deploy: OK
- Checklist de release: criado
- Suítes de teste: disponíveis no repositório

## Gates que exigem ambiente

- executar testes PHP com dependências e banco configurados;
- executar E2E em servidor de homologação;
- validar webhook Mercado Pago em sandbox;
- validar captura/estorno em sandbox;
- verificar headers/cookies via HTTPS real;
- confirmar permissões e acesso ao webroot;
- validar logs e retenção;
- confirmar backups externos do banco.

## Decisão

Não marcar como "produção aprovada" até todos os gates aplicáveis serem executados e registrados.
