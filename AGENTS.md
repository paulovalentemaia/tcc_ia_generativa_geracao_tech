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
| Ollama | modelo padrão `qwen3:1.7b` |

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

## Convenções

- Código de negócio em `app/Services/`, com exceções próprias por etapa
  (`OcrException`, `ExtracaoException`).
- Comprovantes ficam em `storage/app/private/comprovantes/`, nunca em disco público.
- Nomes de tabelas, colunas e rotas em português, como nas specs.
