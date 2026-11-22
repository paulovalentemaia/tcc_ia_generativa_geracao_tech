# 07 — Tela de avaliações

## Objetivo
Tela principal: executar as avaliações e mostrar, de forma didática, o que a IA fez em cada etapa. **É a tela da apresentação.**

## Rotas
- `GET /` e `GET /avaliacoes` → lista
- `POST /avaliacoes/{empresa}` → avaliar uma empresa
- `POST /avaliacoes` → avaliar todas as submetidas
- `GET /avaliacoes/detalhe/{avaliacao}` → detalhe

## Lista
- Cabeçalho com a configuração em uso: "Fim das inscrições: DD/MM/AAAA · Mínimo: N anos · Modelo: X" e link para Configurações.
- Tabela só com empresas `submetido`:
  | Razão social | CNPJ (formatado) | Data de inscrição | Último resultado | Data da avaliação | Ações |
- Último resultado com badge: verde `aprovado`, vermelho `reprovado`, cinza `erro`, "—" se nunca avaliada.
- Ações: **Avaliar** e **Ver detalhe** (se houver avaliação).
- Botão **Avaliar todas** no topo.
- Avaliação **síncrona** (simples para a demo); usar `set_time_limit(0)` em "Avaliar todas".
- Ao clicar, desabilitar o botão e mostrar "Avaliando..." para evitar clique duplo.
- Rodapé com resumo: total, aprovadas, reprovadas, erros.

## Detalhe da avaliação
Organizado na ordem do fluxo, para explicar o processo:
1. **Documento**: link para abrir o comprovante (rota que serve o arquivo do disco privado).
2. **Texto lido pelo OCR**: `texto_ocr` em bloco monoespaçado com rolagem.
3. **Dados extraídos pelo LLM**: `dados_extraidos` formatado + modelo usado.
4. **Verificações**: tabela com as 6 regras — valor cadastrado, valor do comprovante e ✓/✗.
5. **Resultado**: badge, observações e tempo total (`tempo_ms` em segundos).
- Lista das avaliações anteriores da mesma empresa (modelo, resultado, data), para comparar modelos.

## Critérios de aceite
- [ ] A lista mostra só as 7 empresas submetidas.
- [ ] "Avaliar" cria uma nova avaliação e volta à lista com mensagem de sucesso.
- [ ] "Avaliar todas" avalia as 7 e o resumo bate com a tabela de casos da spec 02.
- [ ] O detalhe mostra as 5 seções preenchidas.
- [ ] O comprovante abre pelo link, sem ficar público em `/storage`.
- [ ] Avaliação com erro mostra a mensagem do erro no detalhe.

## Fora de escopo
- Filas/jobs, atualização em tempo real, exportação, paginação (poucos registros).
