# CM Comercial — Release Final

## Estado
17/17 fases técnicas concluídas.

## Último hardening
- `setup.php` fica indisponível depois que o primeiro administrador é criado.
- Removida diretiva `<Directory>` inadequada do `.htaccess` de diretório.
- A pasta `uploads/` possui `.htaccess` próprio para impedir execução de scripts.
- `uploads/.gitkeep` preserva a pasta no pacote.
- Mantida proteção de banco, dados e arquivos sensíveis.

## Validação
- PHP: todos os arquivos `.php` passaram em `php -l`.
- JavaScript: `node --check js/app.js` passou.
- O ambiente desta sessão não possui driver PDO SQLite; o fluxo de banco deve ser validado no servidor de homologação.

## Produção
Pagamento real, frete externo, SMTP, domínio, HTTPS e credenciais continuam dependentes da infraestrutura escolhida.
