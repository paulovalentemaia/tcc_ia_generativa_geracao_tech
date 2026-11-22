# 01 — Ambiente (Docker)

## Objetivo
Subir o ambiente completo em Docker e testar OCR e LLM **isoladamente**, antes de qualquer código de negócio.

## Por que Docker
- O WSL atual tem Ubuntu 20.04 com PHP 7.4 e Python 3.8; o projeto precisa de PHP 8.2+ e Python 3.10+.
- O ambiente fica reproduzível: quem avaliar o trabalho roda `docker compose up`.

## Containers
| Serviço | Imagem | Função | Porta no host |
|---|---|---|---|
| `app` | `docker/app/Dockerfile` (PHP 8.3 + Python 3.11 + Indra OCR) | Laravel + OCR | 8000 |
| `mysql` | `mysql:8.4` | Banco | 3307 |
| `ollama` | `ollama/ollama` | LLM | 11434 |

- PHP e Python ficam **no mesmo container** porque o Laravel chama o script do Indra via `Process`.
- Indra instalado com `pip install indra-ocr` em `/opt/indra` (venv).
- Modelos do Indra ficam no volume `hf-cache`; modelos do Ollama no volume `ollama-data`.
- Limites de memória definidos no compose (máquina com 8 GB).

## Passo a passo

### 1. Construir a imagem
```bash
docker compose build
```

### 2. Criar o projeto Laravel (pela imagem, sem usar o PHP do WSL)
```bash
docker compose run --rm --no-deps app composer create-project laravel/laravel tmp
mv tmp/* tmp/.[!.]* . && rmdir tmp
```
Sem travar a versão: o `laravel/laravel:^11.0` era recusado pelo Composer por avisos de
segurança nas dependências. A versão instalada foi a **Laravel 13**, com **PHP 8.3**.

### 3. Configurar o `.env`
```
APP_URL=http://localhost:8000

DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=tcc_ia
DB_USERNAME=tcc
DB_PASSWORD=tcc

OCR_PYTHON=/opt/indra/bin/python
OCR_SCRIPT=scripts/ocr/ocr.py
```
Dentro dos containers, os serviços se enxergam pelo nome (`mysql`, `ollama`), não por `localhost`.

### 4. Subir tudo
```bash
docker compose up -d
docker compose exec app php artisan migrate
```
Abrir http://localhost:8000.

### 5. Baixar o modelo LLM
```bash
docker compose exec ollama ollama pull qwen3:4b
```

### 6. Testar o Indra
- Copiar um comprovante para `storage/app/private/comprovantes/`.
- Conferir no README oficial (github.com/datalab-to/indra) o comando de CLI e a API Python. **Não inventar comandos.**
- Rodar o Indra dentro do container:
  ```bash
  docker compose exec app /opt/indra/bin/<comando-do-README> storage/app/private/comprovantes/empresa_01.pdf
  ```
- A 1ª execução baixa os modelos (~76 MB).

### 7. Testar o Ollama
```bash
curl http://localhost:11434/api/chat -d '{
  "model": "qwen3:4b",
  "messages": [{"role": "user", "content": "Responda só: ok"}],
  "think": false,
  "stream": false
}'
```

### 8. Registrar
Anotar em `specs/notas-ambiente.md` os comandos que funcionaram, o tempo do OCR e o consumo de RAM (`docker stats`).

## Comandos úteis
- `docker compose exec app php artisan ...` — Artisan
- `docker compose exec app composer ...` — Composer
- `docker compose logs -f app` — logs
- `docker stats` — memória de cada container
- `docker compose down` — parar tudo (volumes são mantidos)

## Critérios de aceite
- [ ] `docker compose up -d` sobe os 3 containers sem erro.
- [ ] http://localhost:8000 mostra a página inicial do Laravel.
- [ ] `php artisan migrate` (no container) roda com sucesso no MySQL.
- [ ] O Indra extrai texto legível, **em português com acentos**, de um comprovante PDF.
- [ ] O Indra extrai texto de um comprovante JPG.
- [ ] O texto contém "NÚMERO DE INSCRIÇÃO", "DATA DE INSCRIÇÃO NO CNPJ", "NOME EMPRESARIAL" e "SITUAÇÃO CADASTRAL" (ou variações próximas).
- [ ] O Ollama responde ao `curl` em poucos segundos.
- [ ] Com tudo rodando, `docker stats` mostra uso total compatível com 8 GB.

## Se der errado
- Indra não lê bem o português: registrar nas notas e parar para avaliar (maior risco do projeto).
- Falta de RAM: trocar para `gemma3:1b` e/ou reduzir `mem_limit` do MySQL.
- Erro de biblioteca ao rodar o Indra: instalar a lib de sistema que faltar no Dockerfile e rodar `docker compose build` de novo.
- Arquivos criados como root: conferir `UID`/`GID` (`id -u`, `id -g`) e reconstruir a imagem.

## Fora de escopo
- Nginx/PHP-FPM, GPU, deploy.
