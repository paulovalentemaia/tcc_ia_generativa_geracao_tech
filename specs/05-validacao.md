# 05 — Validação e orquestração

## Objetivo
Aplicar as regras de negócio em PHP sobre os dados extraídos e gravar a avaliação.

## Service — `app/Services/ValidacaoService.php`
- Método: `validar(Empresa $empresa, array $dados, ConfiguracaoAvaliacao $config): array`
- Retorna `['resultado' => 'aprovado'|'reprovado', 'observacoes' => string[]]`.

## Regras (nesta ordem)
| # | Regra | Observação quando falha |
|---|---|---|
| 1 | `e_comprovante_cnpj` é true | "O arquivo enviado não é um Comprovante de Inscrição de CNPJ." |
| 2 | CNPJ extraído == CNPJ cadastrado | "CNPJ do comprovante (X) diferente do cadastrado (Y)." |
| 3 | Razão social confere (ver normalização) | "Razão social do comprovante (X) diferente da cadastrada (Y)." |
| 4 | Data de inscrição no CNPJ == `data_inscricao_cnpj` | "Data de inscrição no CNPJ do comprovante (X) diferente da informada (Y)." |
| 5 | Data de inscrição no CNPJ + `anos_minimos` ≤ `data_fim_inscricoes` | "Empresa com menos de N anos de inscrição em DD/MM/AAAA (fim das inscrições)." |
| 6 | Situação cadastral == `ATIVA` | "Situação cadastral X (deveria ser ATIVA)." |

- Se a regra 1 falhar: reprovar **imediatamente**, só com essa observação.
- Nas demais: verificar **todas** e acumular as observações.
- Campo extraído `null` → a regra correspondente falha com "Campo X não encontrado no comprovante."
- Sem falhas → `aprovado`, observação "Todas as verificações foram aprovadas."
- CNPJs e datas exibidos nas mensagens já formatados (`00.000.000/0000-00`, `DD/MM/AAAA`).
- Nome fantasia não é verificado.

## Normalização da razão social
Antes de comparar: maiúsculas, remover acentos, remover pontuação, colapsar espaços. Considerar igual se idênticas após normalizar **ou** `similar_text` ≥ 90% (tolera pequenos erros de OCR).

## Orquestrador — `app/Services/AvaliacaoService.php`
- Método: `avaliar(Empresa $empresa): Avaliacao`
- Fluxo: medir tempo → `OcrService` → `ExtracaoService` → `ValidacaoService` → gravar `Avaliacao`.
- Grava sempre: `texto_ocr`, `dados_extraidos`, `modelo_llm`, `tempo_ms`.
- Se `OcrException` ou `ExtracaoException`: gravar com `resultado = erro` e a mensagem em `observacoes` (guardando o que já tiver sido obtido).
- `observacoes` gravadas como texto, uma por linha.
- Bloquear a avaliação se `data_fim_inscricoes` não estiver configurada.

## Comando
`php artisan avaliacoes:executar {empresa_id?}` → avalia uma empresa ou todas as submetidas, imprimindo uma tabela com o resultado.

## Critérios de aceite
- [ ] Teste unitário do `ValidacaoService` para cada regra, com dados fixos (sem chamar OCR/LLM).
- [ ] Regra 5: inscrição 10/03/2025, fim 30/11/2026, 2 anos → reprovado; inscrição 10/03/2024 → aprovado.
- [ ] Mudar `anos_minimos` para 1 altera o resultado da regra 5 sem mudar código.
- [ ] `php artisan avaliacoes:executar` gera os resultados esperados da tabela de casos da spec 02.
- [ ] Com o Ollama parado → avaliação gravada como `erro`.

## Fora de escopo
- Pesos, notas ou pontuação; recurso/reavaliação manual.
