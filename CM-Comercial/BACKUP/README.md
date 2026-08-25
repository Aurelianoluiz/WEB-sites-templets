# BACKUP — CM Comercial

Pasta oficial de pontos de restauração do projeto CM Comercial.

## Ponto de restauração atual

**Backup:** `CM-Comercial-BACKUP-2026-08-25_14-22-19.zip`

**Data/hora:** 25/08/2026 14:22:19 (Brasil, UTC-03:00)

**Baseline:** Release Final v2

**Estado:** 17/17 fases técnicas concluídas

**Arquivos preservados no release:** 55

**SHA-256 do backup ZIP:**

`7538c659c6f208253ea23f3be99b76b11f2bc0cddde70dfc32ad3d5870f0b18b`

## Conteúdo preservado

O ponto de restauração contém o pacote completo de código, configurações e documentação da Release Final v2, incluindo PHP, HTML5, CSS, JavaScript, área administrativa, área do cliente, catálogo, carrinho, checkout, pedidos, estoque, frete, arquitetura de pagamentos, segurança, SEO/acessibilidade, assets, logo e documentação das 17 fases.

## Restauração

1. Baixar o ZIP do ponto de restauração armazenado na conversa/arquivo de release.
2. Validar o SHA-256 informado acima.
3. Descompactar a Release Final v2.
4. Restaurar o conteúdo no ambiente PHP.
5. Restaurar separadamente o banco de dados e o `.env` de produção, quando existirem.
6. Executar a homologação antes da publicação.

## Importante

GitHub não deve receber credenciais, `.env` de produção, bancos de dados reais, certificados privados ou dados pessoais de clientes.

> O ZIP binário completo do ponto de restauração permanece disponível como arquivo de backup desta conversa. Este diretório registra oficialmente o ponto, sua identificação, data/hora e hash para rastreabilidade.
