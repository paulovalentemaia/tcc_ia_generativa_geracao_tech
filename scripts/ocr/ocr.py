#!/usr/bin/env python
"""Le um comprovante (PDF ou imagem) e devolve o texto em JSON.

Uso: python ocr.py <caminho_absoluto_do_arquivo>

Saida no stdout, sempre JSON em UTF-8:
  sucesso -> {"ok": true, "texto": "..."}          exit 0
  falha   -> {"ok": false, "erro": "mensagem"}     exit 1

Nada alem do JSON vai para o stdout. Mensagens de diagnostico vao para o stderr.
Roda com o Python do venv do Indra: /opt/indra/bin/python.
"""

import json
import os
import sys

EXTENSOES = {".pdf", ".jpg", ".jpeg", ".png"}

# So a primeira pagina: o comprovante da Receita tem uma pagina.
MAX_PAGINAS = 1

# O container tem pouca memoria; limitar as threads evita picos.
THREADS = 2


def falhar(mensagem: str) -> None:
    json.dump({"ok": False, "erro": mensagem}, sys.stdout, ensure_ascii=False)
    sys.stdout.write("\n")
    sys.exit(1)


def extrair(caminho: str) -> str:
    # Importado aqui dentro para que um erro de instalacao do Indra vire
    # uma mensagem de erro em JSON, e nao um traceback no stdout.
    import indra_ocr

    conversor = indra_ocr.load(threads=THREADS)

    # convert() devolve um GERADOR de tuplas (indice_da_pagina, Page).
    paginas = list(conversor.convert(caminho, max_pages=MAX_PAGINAS))
    if not paginas:
        raise RuntimeError("o arquivo nao produziu nenhuma pagina")

    _, pagina = paginas[0]

    return "\n".join(linha.text for linha in pagina.lines)


def main() -> None:
    if len(sys.argv) != 2:
        falhar("uso: ocr.py <caminho_do_arquivo>")

    caminho = sys.argv[1]

    if not os.path.isfile(caminho):
        falhar(f"arquivo nao encontrado: {caminho}")

    extensao = os.path.splitext(caminho)[1].lower()
    if extensao not in EXTENSOES:
        falhar(f"extensao nao suportada: {extensao or '(sem extensao)'}")

    try:
        texto = extrair(caminho)
    except Exception as erro:  # noqa: BLE001 - qualquer falha vira JSON
        falhar(f"{type(erro).__name__}: {erro}")
        return

    if not texto.strip():
        falhar("o OCR nao encontrou texto no arquivo")

    json.dump({"ok": True, "texto": texto}, sys.stdout, ensure_ascii=False)
    sys.stdout.write("\n")


if __name__ == "__main__":
    main()
