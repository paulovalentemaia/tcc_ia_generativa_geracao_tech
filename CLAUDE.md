# TCC — Avaliação de comprovantes de CNPJ com IA generativa

Sistema Laravel que avalia automaticamente Comprovantes de Inscrição e de Situação
Cadastral de CNPJ, usando OCR local e um LLM local.

## Stack

| Peça | Versão |
|---|---|
| Laravel | 13 |
| PHP | 8.3 |
| MySQL | 8.4 |
| Python | 3.11 |
| Indra OCR | 1.1.2 (venv em `/opt/indra`) |
| Ollama | modelo padrão `qwen3:4b` |

O projeto foi criado com `composer create-project laravel/laravel` sem travar a versão:
o `^11.0` era recusado pelo Composer por avisos de segurança nas dependências.

## Ambiente

Tudo roda em Docker (`docker-compose.yml`), com três containers:

- `app` — PHP 8.3 + Python 3.11 + Indra OCR, serve o Laravel na porta 8000
- `mysql` — MySQL 8.4, porta 3307 no host
- `ollama` — LLM local, porta 11434

Nunca usar o PHP ou o Python do WSL: o host tem Ubuntu 20.04 com PHP 7.4 e Python 3.8,
abaixo do que o projeto exige. Todo comando passa pelo container:

```sh
docker compose exec app php artisan ...
docker compose exec app composer ...
docker compose exec app /opt/indra/bin/python scripts/ocr/ocr.py <arquivo>
```

Dentro dos containers os serviços se enxergam pelo nome (`mysql`, `ollama`),
nunca por `localhost`.

## Especificações

As specs em `specs/` são a fonte da verdade e devem ser seguidas em ordem:

| Spec | Etapa |
|---|---|
| `00-visao-geral.md` | Problema, regras de avaliação, tabelas |
| `01-ambiente.md` | Docker, Indra, Ollama |
| `02-banco.md` | Migrations, models, seeders |
| `03-ocr.md` | Script Python e `OcrService` |
| `04-extracao.md` | Extração de campos com o LLM |
| `05-validacao.md` | Regras de negócio e orquestração |
| `06-configuracoes.md` | Tela de configurações |
| `07-avaliacoes.md` | Tela de avaliações |

Ao implementar, conferir os critérios de aceite da spec antes de considerar a etapa pronta.

As oito etapas estão implementadas. Duas decisões tomadas durante a execução divergem
do texto original das specs e valem por cima dele:

- **Modelo `qwen3:4b`, não `qwen3:1.7b`.** O 1.7b acertou só 5 dos 7 casos, reprovando
  empresas regulares. A comparação está em `specs/notas-ambiente.md`.
- **A tabela `avaliacoes` ganhou a coluna `verificacoes`** (json), que a spec 02 não
  previa. A tela de detalhe precisa do resultado de cada regra como ele valia na hora da
  avaliação, e recalcular na exibição divergiria do `resultado` gravado sempre que a
  configuração mudasse.

## Convenções

- Código de negócio em `app/Services/`, com exceções próprias por etapa
  (`OcrException`, `ExtracaoException`, `AvaliacaoException`).
- Comprovantes ficam em `storage/app/private/comprovantes/`, nunca em disco público.
  Quem os serve é a rota `avaliacoes.comprovante`, nunca uma URL de `/storage`.
- Nomes de tabelas, colunas e rotas em português, como nas specs.
- **Texto que o usuário lê tem acento; código não.** Strings de tela, mensagens de
  exceção, rótulos das regras e saída dos comandos são escritos em português correto —
  eles aparecem na interface e vão para as capturas do trabalho. Já comentários,
  nomes de variável, métodos e chaves de array seguem sem acento.
- **Sem build de frontend.** O `package.json` declara Tailwind, mas não há `node_modules`
  e o container `app` não tem Node. As telas são Blade com `public/css/app.css` escrito à
  mão; não usar Vite, `@vite` ou classes do Tailwind.
- Quem decide aprovação é o `ValidacaoService`, em PHP determinístico. O LLM só lê o
  documento e preenche campos — essa fronteira é o argumento central do trabalho.
