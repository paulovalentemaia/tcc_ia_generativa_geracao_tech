# 04 — Extração com LLM

## Objetivo
Enviar o texto do OCR ao LLM local e receber os campos do comprovante em JSON estruturado. **Esta é a parte de IA generativa do projeto.**

## Service — `app/Services/ExtracaoService.php`
- Método: `extrair(string $textoOcr): array`
- Lê `modelo_llm` e `ollama_url` de `ConfiguracaoAvaliacao::atual()`.
- Chama `POST {ollama_url}/api/chat` com `Http::timeout(120)`.

## Requisição ao Ollama
```json
{
  "model": "<modelo_llm>",
  "stream": false,
  "think": false,
  "format": { ...schema abaixo... },
  "options": { "temperature": 0, "num_ctx": 2048 },
  "keep_alive": "5m",
  "messages": [
    { "role": "system", "content": "<prompt de sistema>" },
    { "role": "user", "content": "<texto do OCR>" }
  ]
}
```
- `think: false` desliga o raciocínio do Qwen3 (mais rápido, menos memória).
- `format` com o schema obriga a resposta a seguir o JSON.

## Schema de saída
```json
{
  "type": "object",
  "properties": {
    "e_comprovante_cnpj": { "type": "boolean" },
    "cnpj": { "type": ["string", "null"] },
    "razao_social": { "type": ["string", "null"] },
    "data_inscricao_cnpj": { "type": ["string", "null"] },
    "situacao_cadastral": { "type": ["string", "null"] }
  },
  "required": ["e_comprovante_cnpj", "cnpj", "razao_social", "data_inscricao_cnpj", "situacao_cadastral"]
}
```
Nome fantasia **não** é extraído.

## Prompt de sistema (base)
> Você extrai dados de documentos brasileiros. Recebe o texto de um documento obtido por OCR.
> Responda apenas com JSON.
> - `e_comprovante_cnpj`: true somente se o texto for um "Comprovante de Inscrição e de Situação Cadastral" da Receita Federal.
> - `cnpj`: valor do campo "NÚMERO DE INSCRIÇÃO", como aparece.
> - `razao_social`: valor do campo "NOME EMPRESARIAL".
> - `data_inscricao_cnpj`: valor do campo "DATA DE INSCRIÇÃO NO CNPJ", no formato DD/MM/AAAA.
>   Não confundir com "DATA DE CONSTITUIÇÃO", nem com a data de emissão do comprovante.
> - `situacao_cadastral`: valor do campo "SITUAÇÃO CADASTRAL".
> Se um campo não existir no texto, use null. Não invente valores.

O prompt acima é a versão **base**, especificada antes da implementação. O prompt que foi
efetivamente implementado cresceu para 44 linhas ao longo do desenvolvimento — ganhou o
exemplo de rótulos em pares, a lista do que **não** serve e a regra do `null` quando o
documento não é comprovante. Ele está no código, em `ExtracaoService::promptDeSistema()`,
e documentado integralmente, com o histórico de cada acréscimo, em
[`docs/documentacao-do-projeto.md`](../docs/documentacao-do-projeto.md) (seções 2.1 e 2.5).

### Por que não é "DATA DE ABERTURA"

O comprovante aprovado pela Instrução Normativa RFB nº 2.119 não tem esse campo. Ele traz
**"DATA DE INSCRIÇÃO NO CNPJ"** e, logo abaixo, **"DATA DE CONSTITUIÇÃO"**. Nos documentos
de teste as duas coincidem, mas são campos distintos e só a primeira corresponde ao
`data_inscricao_cnpj` do cadastro.

O texto do OCR ainda traz uma terceira data, a de emissão ("Emitido no dia ..."), além de
sobras da impressão do navegador como `about:blank` e o número da página. O prompt precisa
deixar claro qual data interessa.

### O que o OCR entrega

O comprovante é de duas colunas e o OCR achata o layout, então rótulo e valor quase nunca
ficam na mesma linha, e a data do cabeçalho vem grudada no título:

```
NÚMERO DE INSCRIÇÃO
COMPROVANTE DE INSCRIÇÃO E DE SITUAÇÃO 28/09/1966 DATA DE INSCRIÇÃO NO CNPJ
33.000.167/0001-01
CADASTRAL
MATRIZ
NOME EMPRESARIAL
DATA DE CONSTITUIÇÃO
PETROLEO BRASILEIRO S A PETROBRAS
28/09/1966
```

É justamente por isso que a leitura é feita por um LLM e não por expressão regular: o valor
pode aparecer antes do rótulo, depois dele, ou na mesma linha.

O OCR também erra `NÚMERO` como `NÜMERO` de vez em quando, então o prompt não pode depender
da grafia exata dos rótulos.

## Pós-processamento (em PHP, no service)
- `cnpj` → só dígitos.
- `data_inscricao_cnpj` → converter `DD/MM/AAAA` para `Y-m-d`; se inválida, `null`.
- `razao_social` e `situacao_cadastral` → `trim` e maiúsculas.
- Retornar também o JSON bruto do modelo para gravar em `dados_extraidos`.

## Erros
Lançar `ExtracaoException` se: Ollama inacessível, timeout, resposta não for JSON válido ou faltar chave obrigatória.

## Comando de teste
`php artisan extracao:testar {empresa_id}` → roda OCR + extração e imprime o JSON.

## Critérios de aceite
- [ ] Para `empresa_01`, retorna CNPJ, razão social, data e situação corretos.
- [ ] Para `empresa_07` (não é comprovante), retorna `e_comprovante_cnpj: false`.
- [ ] Com o Ollama parado, lança `ExtracaoException` com mensagem clara.
- [ ] Troca de modelo nas configurações (ex.: `gemma3:1b`) funciona sem mudar código.
- [ ] Tempo de resposta registrado (para uso na spec 05).

## Fora de escopo
- Regras de validação (spec 05), RAG, few-shot com vários exemplos.
