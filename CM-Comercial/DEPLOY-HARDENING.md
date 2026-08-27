# Etapa 31 — Hardening e Deploy

## Produção

- `APP_ENV=production`;
- HTTPS obrigatório;
- `MP_ACCESS_TOKEN` e `MP_WEBHOOK_SECRET` somente em variáveis de ambiente;
- não versionar `.env`;
- exibir erros detalhados somente em desenvolvimento;
- PHP atualizado e extensões necessárias habilitadas;
- diretórios sensíveis sem execução pública quando possível;
- permissões mínimas de leitura/escrita;
- backups do banco mantidos fora do webroot.

## Verificações antes do deploy

1. Rodar os testes automatizados disponíveis.
2. Rodar `php -l` nos arquivos PHP.
3. Confirmar HTTPS e headers de segurança no servidor.
4. Confirmar webhook HTTPS e segredo configurado.
5. Confirmar conexão de banco com usuário de privilégios mínimos.
6. Confirmar que arquivos de configuração e logs não estão publicamente acessíveis.

Este documento não substitui a configuração do servidor web nem uma revisão de infraestrutura específica.
