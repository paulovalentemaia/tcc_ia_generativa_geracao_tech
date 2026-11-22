# 03 — OCR

## Objetivo
Transformar o comprovante (PDF ou JPG) em texto, chamando o Indra OCR a partir do Laravel.

## Script Python — `scripts/ocr/ocr.py`
- Roda dentro do container `app` com `/opt/indra/bin/python`.
- Usar a API Python do pacote `indra-ocr` conforme o README oficial (github.com/datalab-to/indra).
- Uso: `python ocr.py <caminho_absoluto_do_arquivo>`
- Aceita `.pdf`, `.jpg`, `.jpeg`, `.png`.
- PDF: processar **apenas a primeira página** (o comprovante tem uma página).
- Saída no stdout, sempre JSON em UTF-8:
  - Sucesso: `{"ok": true, "texto": "..."}`
  - Falha: `{"ok": false, "erro": "mensagem"}`, com exit code diferente de 0.
- Nada além do JSON pode ir para o stdout (logs vão para o stderr).

## Service — `app/Services/OcrService.php`
- Método: `extrairTexto(string $caminhoRelativo): string`
- Resolve o caminho absoluto com `Storage::disk('local')->path()`.
- Executa com `Process::timeout(120)->run([...])` usando `OCR_PYTHON` e `OCR_SCRIPT` do `.env`.
- Lança `OcrException` (exceção própria) se:
  - o arquivo não existir;
  - o processo falhar ou estourar o tempo;
  - o JSON vier inválido ou com `ok: false`;
  - o texto vier vazio.

## Comando de teste
`php artisan ocr:testar {empresa_id}` → imprime o texto extraído do comprovante da empresa.

## Critérios de aceite
- [ ] `docker compose exec app /opt/indra/bin/python scripts/ocr/ocr.py <arquivo empresa_01.pdf>` imprime JSON com `ok: true` e o texto.
- [ ] O mesmo funciona com `empresa_02.jpg`.
- [ ] Arquivo inexistente → JSON com `ok: false` e exit code ≠ 0.
- [ ] `php artisan ocr:testar 1` mostra o texto com acentos corretos.
- [ ] Erros do script viram `OcrException` com mensagem clara.

## Fora de escopo
- PDFs com várias páginas, pré-processamento de imagem, extração de campos (isso é a spec 04).
