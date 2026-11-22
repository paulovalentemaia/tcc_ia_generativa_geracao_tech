# 00 — Visão geral

## Problema
Empresas se inscrevem numa seleção informando razão social, nome fantasia, CNPJ e data de inscrição no CNPJ, e enviam o Comprovante de Inscrição e de Situação Cadastral (PDF ou JPG). Após o fim das inscrições, cada comprovante precisa ser conferido. Hoje isso seria manual.

## Objetivo
Avaliar cada comprovante automaticamente, usando IA generativa local, e registrar o resultado com o motivo.

## Escopo do trabalho
- As inscrições são **mockadas** via seeder (não há telas de cadastro).
- Só são avaliadas empresas com situação `submetido`.
- O sistema tem dois menus:
  - **Configurações**: data de fim das inscrições, modelo LLM e demais parâmetros.
  - **Avaliações**: lista de empresas, execução das avaliações e detalhe dos resultados.

## Regras de avaliação
1. O arquivo é um Comprovante de Inscrição e de Situação Cadastral de CNPJ.
2. O CNPJ do comprovante é igual ao CNPJ cadastrado.
3. A razão social (Nome Empresarial) do comprovante bate com a cadastrada.
4. A data de inscrição no CNPJ do comprovante é igual à `data_inscricao_cnpj` cadastrada.
5. A empresa tem pelo menos N anos (padrão 2) de inscrição **na data de fim das inscrições** configurada.
6. A situação cadastral é `ATIVA`.

O nome fantasia **não** é validado nem gera observação.

## Arquitetura
```
Comprovante (PDF/JPG)
   → Indra OCR (Python, CPU)        → texto
   → LLM pequeno (Ollama)           → JSON com os campos
   → Regras em PHP                  → aprovado / reprovado
   → Tabela avaliacoes + tela
```
Cada peça faz uma coisa: o OCR lê, o LLM entende o texto, o PHP decide.

## Tabelas
- `empresas`: id, razao_social, nome_fantasia, cnpj, data_inscricao_cnpj, arquivo_comprovante, situacao (`criando`/`submetido`), timestamps
- `avaliacoes`: id, empresa_id, resultado (`aprovado`/`reprovado`/`erro`), observacoes, texto_ocr, dados_extraidos (json), modelo_llm, tempo_ms, timestamps
- `configuracoes_avaliacao`: registro único com data_fim_inscricoes, anos_minimos, modelo_llm, ollama_url, timestamps

## Ordem das specs
| Spec | Etapa |
|---|---|
| 01 | Ambiente (Python, Indra, Ollama) |
| 02 | Banco, models e seeders |
| 03 | OCR |
| 04 | Extração com LLM |
| 05 | Validação |
| 06 | Tela de configurações |
| 07 | Tela de avaliações |

## Fora de escopo
- Telas de cadastro/inscrição de empresas.
- Autenticação e perfis de usuário.
- RAG, banco vetorial, APIs externas (ex.: consulta de CNPJ na Receita).
- Filas, deploy, testes de carga.
