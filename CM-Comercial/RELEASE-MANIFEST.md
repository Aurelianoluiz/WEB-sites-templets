# CM Comercial — Release Manifest

## Estado atual

- Data: 2026-08-27
- Branch de origem: `main`
- Estado: Release Candidate / homologação final
- Desenvolvimento técnico: concluído conforme `ETAPAS-FASES.md`.

## Validações registradas

- Pacote anterior teve 12 arquivos PHP analisados e 12/12 passaram no `php -l`.
- CI de PHP está configurada para PHP 8.2, 8.3 e 8.4.
- Testes financeiros, integração, segurança e release gate estão versionados.

## Gates ainda dependentes de ambiente

- execução real da CI;
- banco configurado;
- E2E em servidor de homologação;
- Mercado Pago Sandbox e webhook HTTPS;
- captura/estorno em sandbox;
- validação de HTTPS, cookies e headers;
- observabilidade e retenção de logs;
- backup externo do banco.

## Conteúdo de distribuição

O ZIP final deve incluir os arquivos necessários de `CM-Comercial/` e excluir:

- `.env` e segredos;
- bancos de desenvolvimento temporários;
- logs gerados;
- artefatos temporários de CI.

## Integridade do ZIP final

Preencher somente no empacotamento definitivo:

- nome do arquivo;
- data/hora;
- SHA-256;
- commit de origem.

## Regra

Este manifesto não é, por si só, aprovação para produção nem substitui a execução dos gates dependentes de ambiente.
