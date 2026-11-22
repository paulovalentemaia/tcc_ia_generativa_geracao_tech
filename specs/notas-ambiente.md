# Notas do ambiente — spec 01

Registro do que funcionou de fato ao montar o ambiente. Data: 17/11/2026.

## Versões instaladas

| Peça | Versão |
|---|---|
| Laravel | 13.43.0 |
| PHP | 8.3.36 (cli) |
| MySQL | 8.4.8 |
| Python | 3.11 (venv em `/opt/indra`) |
| indra-ocr | 1.1.2 |
| Ollama | 0.40.16 |
| Modelo LLM | `qwen3:4b` (2,5 GB); o `qwen3:1.7b` (1,4 GB) segue instalado para comparação |

O projeto foi criado com `composer create-project laravel/laravel` sem travar a versão:
o `^11.0` era recusado pelo Composer por avisos de segurança nas dependências.

## Como rodar os comandos

O Docker roda dentro do WSL. Todos os comandos partem de
`~/logosdev/tcc_ia_generativa` **no WSL**, não pelo caminho UNC do Windows, para que o
bind mount `.:/var/www` use o caminho Linux nativo.

```bash
UID=$(id -u) GID=$(id -g) docker compose up -d
docker compose exec app php artisan migrate --force
```

`UID` é somente leitura no bash, então tem que ir como prefixo do comando, nunca com
`export`.

## MySQL

No primeiro `up`, o MySQL demora cerca de 1 minuto para inicializar o datadir. Até lá o
`php artisan migrate` falha com `SQLSTATE[HY000] [2002] Connection refused`. Não é erro
de configuração: basta esperar.

Para esperar antes de migrar:

```bash
until docker compose exec -T mysql mysqladmin ping -h 127.0.0.1 -utcc -ptcc --silent; do sleep 5; done
```

## Indra OCR

### Modelos

A primeira execução baixa 12 arquivos do Hugging Face, uns 9 segundos. Ficam no volume
`hf-cache` (`HF_HOME=/opt/hf-cache`), então só acontece uma vez. Sai um aviso de
`unauthenticated requests to the HF Hub` que pode ser ignorado.

### CLI

```bash
docker compose exec app /opt/indra/bin/indra-ocr <arquivo> --format text --max-pages 1
```

- `--format` aceita `md`, `text` ou `json`. O `json` traz caixas, confiança e tempos por
  linha, útil para depurar.
- `--max-pages 1` atende ao requisito de ler só a primeira página.
- `--cores N` limita as threads (por padrão usa todos os núcleos visíveis).
- A saída vai toda para o stdout; o stderr fica livre para logs.

### API Python (para a spec 03)

O pacote instala o módulo `indra_ocr` (com underline), não `indra`:

```python
import indra_ocr

conv = indra_ocr.load(threads=2)                       # devolve um Converter
paginas = list(conv.convert(caminho, max_pages=1))     # convert() é um GERADOR
indice, page = paginas[0]                              # cada item é uma tupla (int, Page)

texto = "\n".join(linha.text for linha in page.lines)  # Line tem .text, .box, .conf
```

Três pegadinhas, todas descobertas na marra:

1. `Converter` não é chamável — o método é `conv.convert(...)`.
2. `convert()` devolve um gerador, não uma lista, então `len()` não funciona direto.
3. Cada item é a tupla `(indice_da_pagina, Page)`, não um `Page`.

`Page` também tem `.markdown`, `.blocks`, `.source` (`ocr` ou `text`) e `.timings`.

### Qualidade do português

Testado com um arquivo gerado para isso (`storage/app/private/comprovantes/_teste_ocr.jpg`
e `.pdf`), já que ainda não há comprovantes reais.

O resultado foi muito bom. Acentuação lida corretamente em minúsculas e maiúsculas:

```
NOME EMPRESARIAL: PADARIA SÃO JOÃO LTDA
SITUAÇÃO CADASTRAL: ATIVA
Minúsculas: ação, coração, pão, três, você
MAIÚSCULAS: AÇÃO CORAÇÃO INSCRIÇÃO
à noite, avó, herói, porém, já, avões, ônibus, ÍNDIA
```

Confiança por linha entre 0,98 e 0,99.

**Único erro recorrente:** `NÚMERO` sai como `NÜMERO` (trema no lugar do acento agudo).
Aconteceu em todas as execuções, tanto no JPG quanto no PDF, e justamente no rótulo
`NÚMERO DE INSCRIÇÃO`, que é um dos campos que o LLM precisa achar. Em contrapartida
`MAIÚSCULAS` e `ÍNDIA` saíram certos, então não é uma troca sistemática de todo Ú.

Consequências para as specs seguintes:

- Spec 04: o prompt não pode exigir a grafia exata de `NÚMERO DE INSCRIÇÃO`. O LLM lida
  bem com a variação, mas vale evitar casamento literal.
- Spec 05: a normalização da razão social já remove acentos antes de comparar, o que
  neutraliza esse tipo de troca.

### Tempos

| Operação | Tempo |
|---|---|
| 1ª execução (baixando modelos) | ~14 s |
| JPG 1240x900, já com cache | ~2,1 s |
| PDF de 1 página, já com cache | ~2,4 s |

O tempo de OCR em si é de ~95 ms por página; o resto é carregar os modelos ONNX a cada
processo novo. Como o Laravel chama o script por `Process`, esse custo se repete em toda
avaliação. Aceitável para a demonstração.

## Ollama

```bash
docker compose exec ollama ollama pull qwen3:1.7b
curl http://localhost:11434/api/chat -d '{"model":"qwen3:1.7b","messages":[{"role":"user","content":"Responda so: ok"}],"think":false,"stream":false}'
```

Responde normalmente. Primeira chamada 19,6 s (9,9 s só para carregar o modelo),
chamadas seguintes com o modelo quente em torno de 10 s.

**Achado importante para a spec 04:** nesta versão do Ollama, `think: false` sozinho
**não** desliga o raciocínio do Qwen3. Ele é traduzido para um `/no_think` acrescentado
ao prompt, e o modelo continua produzindo o raciocínio, que vem dentro de
`message.content` terminando em `</think>`.

Com o campo `format` e o schema JSON, porém, a resposta sai limpa:

```json
{ "e_comprovante_cnpj": true, "cnpj": "12.345.678/0001-95" }
```

Ou seja: o `format` com schema da spec 04 resolve o problema. Mesmo assim o
`ExtracaoService` deve descartar tudo que vier antes de `</think>`, caso ele apareça.

## Memória (`docker stats`, tudo rodando, modelo carregado)

| Container | Uso | Limite | % |
|---|---|---|---|
| `tcc_ollama` | 2,68 GiB | 3 GiB | 89% |
| `tcc_mysql` | 496 MiB | 768 MiB | 65% |
| `tcc_app` | 216 MiB | 1 GiB | 21% |

Total de cerca de 3,4 GiB, confortável numa máquina de 8 GB. O Ollama é quem está mais
perto do limite: se faltar memória ao trocar de modelo, o caminho é reduzir o
`keep_alive` ou passar para `gemma3:1b`, como já previa a spec.

## Com os comprovantes reais

Os oito documentos foram baixados do site da Receita e passaram pelo OCR. Todos são
legíveis e as datas conferem com o cadastro. Os arquivos `_teste_ocr.*` foram apagados.

O caso da empresa 02 pede comprovante em imagem. Começou como rasterização da primeira
página do PDF, e em 21/11/2026 foi substituído por uma foto de celular (`empresa_02.jpeg`),
que é um teste bem mais honesto. O resultado está na seção seguinte.

Dois achados do documento real, já refletidos nas specs 00, 01, 04, 05 e 06:

1. **Não existe campo "DATA DE ABERTURA".** O comprovante atual, aprovado pela Instrução
   Normativa RFB nº 2.119, traz "DATA DE INSCRIÇÃO NO CNPJ" e "DATA DE CONSTITUIÇÃO".
   Nos oito documentos as duas coincidem, mas são campos distintos.
2. **O texto tem três datas competindo.** Além das duas acima, aparece a data de emissão
   ("Emitido no dia ..."), junto de sobras da impressão do navegador (`about:blank`, o
   número da página). O prompt da spec 04 precisa dizer qual delas interessa.

O layout de duas colunas sai achatado, com rótulo e valor em linhas separadas e fora de
ordem. É o que justifica usar o LLM em vez de expressão regular.

### O arquivo da empresa 07

O caso 07 precisa de um arquivo que **não** seja comprovante de CNPJ, para exercitar a
regra 1. Está sendo usado um **Fato Relevante** da própria Lojas Renner, documento
público de companhia aberta, baixado do site de relações com investidores.

A escolha é melhor do que um documento genérico porque o cabeçalho traz o CNPJ correto
da empresa:

```
LOJAS RENNER S.A.
CNPJ/MF nº 92.754.738/0001-62
NIRE 43300004848
Companhia Aberta de Capital Autorizado
FATO RELEVANTE
```

Ou seja, não basta o modelo achar um CNPJ no texto e concluir que é comprovante. Ele
precisa reconhecer o **tipo** do documento, que é exatamente o que a regra 1 cobra.

## Escolha do modelo — qwen3:1.7b x qwen3:4b

Medido em 21/11/2026, com os oito documentos reais e as regras da spec 05 ja
implementadas. Os dois modelos sao locais e gratuitos; rodam no mesmo container
Ollama, sem chave de API.

| # | Empresa | Esperado | qwen3:4b | qwen3:1.7b |
|---|---|---|---|---|
| 1 | Petrobras | aprovado | aprovado | **reprovado** |
| 2 | Banco do Brasil | aprovado | aprovado | **reprovado** |
| 3 | Raia Drogasil | reprovado (regra 5) | ok | ok |
| 4 | Ambev | reprovado (regra 2) | ok | ok |
| 5 | Magazine Luiza | reprovado (regra 4) | ok | ok |
| 6 | Lojas Americanas | reprovado (regra 6) | ok | ok, motivo errado |
| 7 | Lojas Renner | reprovado (regra 1) | ok | ok |

**Acerto: 7/7 no 4b, 5/7 no 1.7b.** Tempo por avaliacao (OCR + LLM + regras):
13,5 a 31,0 s no 4b (media 23 s) contra 9,6 a 20,6 s no 1.7b (media 13 s).

Os dois erros do 1.7b sao exatamente as duas armadilhas que a spec 04 documenta:

1. **Nome fantasia no lugar do nome empresarial.** Na empresa 01 extraiu
   `PETROBRAS`, que e o titulo do estabelecimento, em vez de
   `PETROLEO BRASILEIRO S A PETROBRAS`.
2. **Data de emissao no lugar da data de inscricao.** Nas empresas 02 e 06
   extraiu `17/11/2026`, o dia em que o comprovante foi emitido, entre as tres
   datas que competem no documento.

O prompt alerta sobre as duas de forma explicita, com exemplo. O modelo menor
simplesmente nao sustenta a instrucao — e a diferenca nao e de formato nem de
JSON invalido, e de compreensao do documento.

Como os dois erros reprovam empresas regulares (falso negativo, o pior erro
possivel neste dominio), o **qwen3:4b passou a ser o modelo oficial**, e as
specs 01/02, o CLAUDE.md, o seeder e o padrao do model foram atualizados. O
`mem_limit` do container Ollama subiu de 3g para 5g por causa dele.

O 1.7b continua instalado: a troca de modelo pela tela de configuracoes (spec 06)
e justamente o que permite reproduzir esta comparacao na apresentacao.

## Tempos com o sistema completo

Medido em 21/11/2026, banco limpo, sete comprovantes pela tela e pela linha de
comando. O tempo e o total da avaliacao: OCR, leitura pelo LLM e as seis regras.

| Execucao | Tempo |
|---|---|
| Primeira avaliacao, modelo frio | 50 a 74 s |
| Avaliacoes seguintes, modelo quente | 20 a 35 s |
| Lote das sete empresas | cerca de 3,5 minutos |

A primeira chamada paga o carregamento do qwen3:4b na memoria, e o `keep_alive` de
5 minutos do ExtracaoService mantem o modelo quente entre avaliacoes.

**Para a apresentacao:** avaliar uma empresa qualquer antes de comecar, para
aquecer o modelo. Sem isso, a primeira avaliacao demonstrada leva o dobro do
tempo das outras e parece que o sistema travou.

O OCR em si responde em 1,5 a 3,4 s por documento; o resto e o LLM.

## A foto de celular da empresa 02

Em 21/11/2026 o comprovante rasterizado foi trocado por uma foto de celular
(`empresa_02.jpeg`, 132 KB). **A avaliacao passou: aprovado, como o caso pede.**
Mas o motivo merece registro, porque e uma fragilidade e nao um acerto limpo.

Comparando o OCR do mesmo comprovante nos dois formatos:

| | PDF | Foto |
|---|---|---|
| Caracteres lidos | 1349 | 1316 |
| Vezes que `01/08/1966` aparece | 2 | **1** |
| Rotulo `DATA DE INSCRICAO NO CNPJ` | presente | **ausente** |
| Rotulo `DATA DE CONSTITUICAO` | presente | presente |
| Rotulos `NOME EMPRESARIAL`, `SITUACAO CADASTRAL` | presentes | presentes |

Na foto, o rotulo da data de inscricao saiu corrompido como `AO NO` e o valor ao
lado do titulo desapareceu. Sobrou uma data so no documento, a de constituicao.

O LLM devolveu `01/08/1966` e a regra 4 aprovou — mas o valor que ele usou veio do
campo **errado**. Funcionou porque nestes oito documentos a data de inscricao e a
de constituicao coincidem, como ja estava anotado acima. Num comprovante em que as
duas divergissem, essa qualidade de imagem daria resposta errada.

Vale notar tambem que a foto e **de uma tela**, nao de papel: o OCR capturou
`empresa_02.jpg`, `17/11/2026, 21:43`, `about: blank` e `Pesquisar`, que sao
elementos da janela do navegador. Entao o teste cobre reflexo e ruido de tela, mas
ainda nao cobre inclinacao, sombra e dobra de uma folha impressa fotografada.

Duas conclusoes para o trabalho:

1. O gargalo de qualidade e o **OCR**, nao o LLM. O modelo se comportou bem com o
   texto que recebeu; o que faltou foi o texto.
2. A regra 4 depende de um rotulo que pode simplesmente nao sobreviver a uma foto.
   Um sistema de producao deveria distinguir "campo ausente" de "campo divergente"
   com mais cuidado do que o estado atual — hoje, se o rotulo cai mas um valor
   plausivel sobra por perto, o LLM preenche com o que achou.
