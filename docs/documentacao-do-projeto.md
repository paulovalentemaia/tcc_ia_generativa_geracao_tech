# Documentação do Projeto

**Avaliação automatizada de Comprovantes de Inscrição e de Situação Cadastral de CNPJ
com IA Generativa**

Projeto prático do Curso de IA Generativa. Código-fonte, especificações e histórico de
desenvolvimento no repositório; este documento cobre os três itens pedidos no manual de
entrega, na ordem em que foram pedidos.

---

## 1. Descrição do problema

Uma seleção recebe inscrições de empresas. Cada empresa informa razão social, nome
fantasia, CNPJ e data de inscrição no CNPJ, e anexa o **Comprovante de Inscrição e de
Situação Cadastral** emitido pela Receita Federal, em PDF ou imagem.

Encerradas as inscrições, alguém precisa abrir cada comprovante e conferir, campo por
campo, se o documento confirma o que foi declarado. São seis verificações por empresa:

1. o arquivo anexado é realmente um comprovante de CNPJ;
2. o CNPJ do comprovante é o mesmo que foi cadastrado;
3. a razão social confere;
4. a data de inscrição no CNPJ confere;
5. a empresa tem o tempo mínimo de inscrição exigido pelo edital;
6. a situação cadastral está `ATIVA`.

### A dor

O trabalho é repetitivo, lento e silenciosamente propenso a erro. Três armadilhas do
próprio documento explicam por quê:

- o comprovante traz **três datas** — data de inscrição no CNPJ, data de constituição e
  data de emissão —, e só a primeira interessa;
- traz **dois nomes parecidos** — nome empresarial e título do estabelecimento (nome de
  fantasia) —, e só o primeiro é verificado;
- o layout é de **duas colunas**, o que confunde a leitura visual rápida.

Conferente cansado troca data de constituição por data de inscrição, ou nome fantasia por
razão social. O erro é invisível: nada no resultado indica que a comparação foi feita com
o campo errado.

### O que o sistema entrega

Cada comprovante é avaliado em cerca de 25 segundos, com o resultado (`aprovado`,
`reprovado` ou `erro`) e **o motivo de cada reprovação registrado por escrito**. A tela de
detalhe mostra o documento, o texto que o OCR leu, o JSON que o LLM extraiu e a tabela das
seis regras com o valor cadastrado e o valor do comprovante lado a lado — de modo que a
decisão é auditável, e não apenas emitida.

Todo o processamento é local: OCR e LLM rodam em contêiner na própria máquina, sem API
paga, sem chave e sem enviar documento de terceiro para fora.

---

## 2. Engenharia de prompts

### 2.1 Instruções do sistema

Prompt enviado como mensagem `system` em toda chamada, reproduzido integralmente.
Fonte: `app/Services/ExtracaoService.php`, método `promptDeSistema()`.

```text
Voce extrai dados de documentos brasileiros. Recebe o texto de um documento obtido por OCR.
Responda apenas com JSON.

O texto vem de um layout de duas colunas e sai desorganizado: o valor de um campo pode
aparecer antes do rotulo, depois dele, ou na mesma linha. Procure o valor em todo o texto.
O OCR tambem erra acentos, entao os rotulos podem vir com pequenas variacoes de grafia.

E comum os rotulos virem em pares, seguidos dos valores na mesma ordem. Exemplo:

    NOME EMPRESARIAL
    DATA DE CONSTITUICAO
    FULANO COMERCIO DE ALIMENTOS LTDA
    15/04/2010
    TITULO DO ESTABELECIMENTO (NOME DE FANTASIA)
    PORTE
    MERCADINHO DO FULANO

Ali o NOME EMPRESARIAL e "FULANO COMERCIO DE ALIMENTOS LTDA", a DATA DE CONSTITUICAO e
15/04/2010 e "MERCADINHO DO FULANO" e apenas o nome fantasia, que nao interessa.

Campos:
- e_comprovante_cnpj: true somente se o proprio texto trouxer o titulo "COMPROVANTE DE
  INSCRICAO E DE SITUACAO CADASTRAL" junto dos rotulos "NUMERO DE INSCRICAO" e
  "SITUACAO CADASTRAL". Se esses elementos nao aparecerem, responda false, mesmo que o
  documento cite um CNPJ, traga o nome da empresa ou pareca oficial. Contratos, notas
  fiscais, atas, estatutos, fatos relevantes e balancos citam CNPJ e nao sao
  comprovantes. Quando a resposta for false, os outros quatro campos devem ser null:
  nao copie valores de um documento que nao e comprovante.
- cnpj: valor do campo "NUMERO DE INSCRICAO", como aparece.
- razao_social: valor do campo "NOME EMPRESARIAL". Para encontra-lo, ache a linha que
  contem exatamente "NOME EMPRESARIAL" e pegue a primeira linha seguinte que NAO seja
  outro rotulo. Sao rotulos, entre outros: DATA DE CONSTITUICAO, PORTE, TITULO DO
  ESTABELECIMENTO (NOME DE FANTASIA), CODIGO E DESCRICAO DA ATIVIDADE ECONOMICA.
  O valor que vem depois de "TITULO DO ESTABELECIMENTO (NOME DE FANTASIA)" e o nome
  fantasia e NAO serve, mesmo que se pareca com o nome da empresa.
- data_inscricao_cnpj: valor do campo "DATA DE INSCRICAO NO CNPJ", no formato DD/MM/AAAA.
  Nao use a "DATA DE CONSTITUICAO", que fica logo abaixo e pode ter outro valor.
  Nao use a data de emissao do comprovante ("Emitido no dia ..."), nem a data que aparece
  no topo da pagina impressa.
- situacao_cadastral: valor do campo "SITUACAO CADASTRAL". E uma palavra so, como ATIVA,
  BAIXADA, INAPTA ou SUSPENSA. Nao confunda com "DATA DA SITUACAO CADASTRAL" nem com
  "MOTIVO DE SITUACAO CADASTRAL".

Se um campo nao existir no texto, use null. Nao invente valores.
```

O prompt é escrito sem acentuação, de propósito. O texto que ele vai analisar vem de OCR,
que erra os rótulos de forma inconsistente: nos sete comprovantes, `NÚMERO DE INSCRIÇÃO`
saiu correto cinco vezes e, nas outras duas, como `TNUMERO DE INSCRIÇÃO` — com um `T` a
mais e o acento perdido. (Num arquivo sintético usado no levantamento do ambiente, o mesmo
rótulo havia saído como `NÜMERO`, com trema.) Grafar os rótulos sem acento no prompt evita
ancorar o modelo numa forma exata que o OCR não reproduz, e o próprio prompt reforça isso
ao avisar que "os rotulos podem vir com pequenas variacoes de grafia".

### 2.2 Variáveis e parâmetros

**O que varia a cada execução:**

| Variável | Origem | Papel |
|---|---|---|
| Texto do comprovante | saída do OCR | entra como mensagem `user`, sem nenhum preâmbulo |
| Modelo | coluna `modelo_llm` da tabela `configuracoes_avaliacao`, editável pela tela de configurações | permite trocar de modelo e reavaliar sem mexer em código |
| URL do Ollama | coluna `ollama_url` | aponta para o serviço no Docker |

A separação é intencional: o prompt é fixo e versionado junto do código; o que varia é o
documento. Não há template com interpolação de strings — o texto do OCR nunca é
concatenado dentro do prompt de sistema, o que elimina uma via de injeção de instruções
por conteúdo do documento.

**Parâmetros fixos da chamada** (`POST {ollama_url}/api/chat`):

| Parâmetro | Valor | Por quê |
|---|---|---|
| `temperature` | `0` | a mesma entrada precisa dar a mesma saída: é uma decisão administrativa, não texto criativo |
| `num_ctx` | `4096` | prompt e documento somados ficam entre 900 e 1.450 tokens nos arquivos de teste (estimando 4 caracteres por token), sendo o caso 07 o maior; 4096 dá folga sem pesar na memória do contêiner |
| `think` | `false` | desliga o raciocínio do Qwen3 — mais rápido e mais econômico de memória |
| `stream` | `false` | a resposta é consumida de uma vez pelo backend |
| `keep_alive` | `5m` | mantém o modelo quente entre avaliações do mesmo lote |
| `format` | JSON Schema (abaixo) | obriga a resposta a seguir a estrutura |

**Saída estruturada por JSON Schema**, no campo `format`:

```json
{
  "type": "object",
  "properties": {
    "e_comprovante_cnpj": { "type": "boolean" },
    "cnpj":                { "type": ["string", "null"] },
    "razao_social":        { "type": ["string", "null"] },
    "data_inscricao_cnpj": { "type": ["string", "null"] },
    "situacao_cadastral":  { "type": ["string", "null"] }
  },
  "required": [
    "e_comprovante_cnpj", "cnpj", "razao_social",
    "data_inscricao_cnpj", "situacao_cadastral"
  ]
}
```

O schema resolveu um problema concreto, e não apenas de forma. Nesta versão do Ollama, o
`think: false` **não** desliga o raciocínio do Qwen3: ele é traduzido para um `/no_think`
no prompt, e o modelo continua escrevendo o raciocínio dentro de `message.content`,
terminando em `</think>`. Com o `format` preenchido, a resposta sai limpa. Por segurança,
o código ainda descarta tudo que venha antes de `</think>`, caso apareça.

### 2.3 Contexto: por que o prompt precisa ser longo

Um prompt de três linhas não resolve este documento. O OCR achata o layout de duas
colunas, e o resultado real é este:

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

Observe o que acontece aqui: o rótulo `DATA DE INSCRIÇÃO NO CNPJ` aparece **depois** do
seu próprio valor, `28/09/1966`, e no meio do título do documento. O CNPJ aparece **antes**
do rótulo que o nomeia. E `NOME EMPRESARIAL` é seguido de outro rótulo antes de chegar ao
valor.

É exatamente por isso que a leitura é feita por um LLM e não por expressão regular: não
existe posição fixa para ancorar. Qualquer regex que funcionasse neste documento quebraria
no próximo com um campo a mais.

Daí as quatro decisões que moldaram o prompt:

1. **Dizer que a ordem não é confiável** — "o valor pode aparecer antes do rotulo, depois
   dele, ou na mesma linha. Procure o valor em todo o texto."
2. **Dar um exemplo de rótulos em pares**, com a resposta correta explicitada, porque a
   estrutura "rótulo, rótulo, valor, valor" é o padrão que mais engana.
3. **Nomear os campos que não servem** — nome fantasia, data de constituição, data de
   emissão —, em vez de só nomear os que servem. Negar explicitamente foi mais eficaz que
   descrever o alvo.
4. **Avisar que a grafia dos rótulos varia**, por causa dos acentos que o OCR erra.

### 2.4 Exemplos de saída

Três casos reais, com o JSON bruto devolvido pelo modelo e o resultado após o
pós-processamento em PHP (CNPJ reduzido a dígitos, data convertida para `Y-m-d`, textos em
maiúsculas).

#### Caso A — comprovante válido (PDF)

Entrada: comprovante da Petrobras. Saída do modelo:

```json
{
  "e_comprovante_cnpj": true,
  "cnpj": "33.000.167/0001-01",
  "razao_social": "PETROLEO BRASILEIRO S A PETROBRAS",
  "data_inscricao_cnpj": "28/09/1966",
  "situacao_cadastral": "ATIVA"
}
```

Pós-processado:

```json
{
  "e_comprovante_cnpj": true,
  "cnpj": "33000167000101",
  "razao_social": "PETROLEO BRASILEIRO S A PETROBRAS",
  "data_inscricao_cnpj": "1966-09-28",
  "situacao_cadastral": "ATIVA"
}
```

As duas armadilhas foram evitadas: o modelo pegou `28/09/1966` da data de inscrição, e não
a data de constituição logo abaixo, e devolveu o nome empresarial completo, não o nome
fantasia. Resultado da avaliação: **aprovado**.

#### Caso B — documento que não é comprovante

Entrada: um **Fato Relevante** da Lojas Renner, documento público de companhia aberta. O
caso é deliberadamente difícil, porque o cabeçalho traz o CNPJ correto da empresa:

```
LOJAS RENNER S.A.
CNPJ/MF nº 92.754.738/0001-62
NIRE 43300004848
Companhia Aberta de Capital Autorizado
FATO RELEVANTE
```

Saída do modelo:

```json
{
  "e_comprovante_cnpj": false,
  "cnpj": "92.754.738/0001-62",
  "razao_social": "LOJAS RENNER S.A.",
  "data_inscricao_cnpj": null,
  "situacao_cadastral": null
}
```

O essencial está certo: `e_comprovante_cnpj: false`. O modelo não se deixou convencer pela
presença de um CNPJ válido nem pela aparência oficial do documento — reconheceu o **tipo**,
que é o que a regra 1 cobra. Resultado: **reprovado**, com a observação "O arquivo enviado
não é um Comprovante de Inscrição de CNPJ."

Vale registrar um desvio: o prompt manda deixar os outros quatro campos em `null` quando a
resposta é `false`, e o modelo ainda preencheu CNPJ e razão social. É inofensivo aqui,
porque a regra 1 reprova de imediato e as outras cinco nem são avaliadas, mas é um exemplo
honesto de instrução não obedecida ao pé da letra.

#### Caso C — foto de celular, com campo perdido

Entrada: o mesmo tipo de comprovante, fotografado em vez de exportado em PDF. Saída:

```json
{
  "e_comprovante_cnpj": true,
  "cnpj": "00.000.000/0001-91",
  "razao_social": "BANCO DO BRASIL SA",
  "data_inscricao_cnpj": "01/08/1966",
  "situacao_cadastral": "ATIVA"
}
```

A avaliação passou — mas por sorte, e isso merece destaque. Comparando o OCR do mesmo
comprovante nos dois formatos:

| | PDF | Foto |
|---|---|---|
| Caracteres lidos | 1.349 | 1.316 |
| Vezes que `01/08/1966` aparece | 2 | **1** |
| Rótulo `DATA DE INSCRIÇÃO NO CNPJ` | presente | **ausente** (saiu como `AO NO`) |
| Rótulo `DATA DE CONSTITUIÇÃO` | presente | presente |

Na foto, o rótulo da data de inscrição foi corrompido pelo OCR e o valor ao lado do título
desapareceu. Sobrou uma única data no documento: a de constituição. O modelo devolveu essa,
e a verificação aprovou porque, nos oito documentos de teste, as duas datas coincidem.

**Num comprovante em que elas divergissem, essa qualidade de imagem daria resposta
errada.** A conclusão é relevante para o projeto: aqui o gargalo não é o LLM — ele se
comportou bem com o texto que recebeu. O que faltou foi o texto.

### 2.5 Iterações do prompt

O prompt começou com nove linhas, especificadas antes da implementação
(`specs/04-extracao.md`):

> Você extrai dados de documentos brasileiros. Recebe o texto de um documento obtido por
> OCR. Responda apenas com JSON.
> - `e_comprovante_cnpj`: true somente se o texto for um "Comprovante de Inscrição e de
>   Situação Cadastral" da Receita Federal.
> - `cnpj`: valor do campo "NÚMERO DE INSCRIÇÃO", como aparece.
> - `razao_social`: valor do campo "NOME EMPRESARIAL".
> - `data_inscricao_cnpj`: valor do campo "DATA DE INSCRIÇÃO NO CNPJ", no formato
>   DD/MM/AAAA. Não confundir com "DATA DE CONSTITUIÇÃO", nem com a data de emissão.
> - `situacao_cadastral`: valor do campo "SITUAÇÃO CADASTRAL".
> Se um campo não existir no texto, use null. Não invente valores.

Cada acréscimo até a versão final respondeu a uma falha observada:

| Acréscimo | Falha que resolveu |
|---|---|
| Aviso de que a ordem rótulo/valor não é confiável | o modelo procurava o valor só depois do rótulo |
| Exemplo de rótulos em pares, com a resposta explicitada | confusão sistemática entre nome empresarial e nome fantasia |
| Lista do que **não** serve (nome fantasia, data de constituição, data de emissão) | descrever só o alvo não bastava; negar o concorrente foi mais eficaz |
| Exigir o título e os dois rótulos para `e_comprovante_cnpj: true` | documentos que citam CNPJ eram aceitos como comprovante |
| Regra "quando for false, os outros campos são null" | o modelo copiava dados de documento que não era comprovante |
| Aviso sobre variação de grafia dos rótulos | o OCR escreveu `TNUMERO` no lugar de `NÚMERO` em dois dos sete documentos |

### 2.6 O limite do prompt

A evidência mais forte deste projeto sobre engenharia de prompts é negativa, e por isso
mesmo vale registrá-la: **o mesmo prompt, palavra por palavra, em dois modelos de tamanhos
diferentes.**

| Modelo | Acerto nos 7 casos | Tempo médio |
|---|---|---|
| `qwen3:4b` | **7/7** | 23 s |
| `qwen3:1.7b` | 5/7 | 13 s |

Os dois erros do modelo menor:

1. extraiu `PETROBRAS` — o nome fantasia — no lugar do nome empresarial;
2. extraiu `17/11/2026` — a data de emissão — no lugar da data de inscrição.

São **exatamente** as duas armadilhas que o prompt alerta de forma explícita, com exemplo
resolvido. Não houve falha de formato nem JSON inválido: o schema garantiu a estrutura nos
dois modelos. A falha foi de compreensão do documento.

Isto é, o prompt não é uma alavanca ilimitada. Ele pode explicitar o que distinguir, mas
não pode fazer o modelo distinguir além da capacidade que ele tem. Há um piso de
capacidade abaixo do qual instrução não compensa — e, neste domínio, os dois erros do
modelo menor reprovariam empresas regulares, que é o pior erro possível. Por isso o
`qwen3:4b` foi adotado, apesar dos dez segundos a mais por avaliação.

---

## 3. Arquitetura da solução

### Visão geral

```
Comprovante (PDF ou JPG)
   ↓
Indra OCR (Python, CPU)      → texto corrido
   ↓
LLM local via Ollama         → JSON com os cinco campos
   ↓
Regras em PHP                → aprovado / reprovado + observações
   ↓
MySQL + tela de detalhe
```

Cada peça faz uma coisa só: **o OCR lê, o LLM entende, o PHP decide.**

### Fluxo de dados, etapa por etapa

| # | Etapa | Arquivo | Recebe | Devolve |
|---|---|---|---|---|
| 1 | Tela | `resources/views/avaliacoes/index.blade.php` | clique em "Avaliar" | `POST /avaliacoes/{empresa}` |
| 2 | Controller | `app/Http/Controllers/AvaliacaoController.php` | id da empresa | delega e redireciona com mensagem |
| 3 | Orquestrador | `app/Services/AvaliacaoService.php` | `Empresa` | registro de `Avaliacao`, medindo o tempo total |
| 4 | OCR | `app/Services/OcrService.php` → `scripts/ocr/ocr.py` | caminho do arquivo no disco privado | texto corrido |
| 5 | Extração | `app/Services/ExtracaoService.php` | texto do OCR | JSON com cinco campos, mais o modelo e o tempo |
| 6 | Validação | `app/Services/ValidacaoService.php` | campos extraídos + cadastro + configuração | resultado, observações e as seis verificações |
| 7 | Persistência | tabela `avaliacoes` | tudo acima | linha com texto do OCR, JSON, verificações, modelo e tempo |
| 8 | Detalhe | `resources/views/avaliacoes/detalhe.blade.php` | `Avaliacao` | as cinco seções do fluxo na tela |

### A fronteira entre o LLM e a decisão

A decisão de arquitetura central do projeto: **o LLM nunca aprova nem reprova ninguém.**

Ele recebe o texto do OCR e preenche cinco campos. Quem decide é o `ValidacaoService`,
PHP determinístico, com as seis regras do edital. A consequência prática é que a parte que
toma decisão sobre a inscrição de uma empresa pode ser testada sem OCR e sem LLM, com
dados fixos — e é: há 21 testes unitários cobrindo as seis regras, que rodam em menos de
um segundo, sem subir contêiner nenhum.

O inverso seria pedir ao modelo "este comprovante está aprovado?". Funcionaria em alguns
casos, e falharia de forma não auditável, não reproduzível e não testável.

### Comunicação entre os processos

| Fronteira | Mecanismo | Contrato |
|---|---|---|
| PHP → Python (OCR) | `Process` do Laravel, mesmo contêiner | JSON no stdout: `{"ok": true, "texto": "..."}` ou `{"ok": false, "erro": "..."}` |
| PHP → Ollama (LLM) | HTTP, contêineres distintos na rede do Compose | `POST /api/chat`, resposta restrita por JSON Schema |
| Aplicação → banco | Eloquent | tabelas `empresas`, `avaliacoes`, `configuracoes_avaliacao` |

Cada fronteira tem exceção própria — `OcrException`, `ExtracaoException`,
`AvaliacaoException` —, e a avaliação é gravada mesmo quando algo falha no caminho,
preservando o que já foi obtido. Com o Ollama parado, por exemplo, a avaliação fica
registrada como `erro`, com a mensagem do erro e o texto do OCR que já havia sido lido.

### Configuração em tempo de execução

Quatro parâmetros são editáveis pela tela de configurações, sem tocar em código nem em
`.env`: data de fim das inscrições, anos mínimos de inscrição, modelo LLM (a lista é
buscada no próprio Ollama) e URL do Ollama.

É o que torna a comparação de modelos reproduzível na demonstração: troca-se o modelo,
reavalia-se a mesma empresa, e a tela de detalhe lista as avaliações anteriores lado a
lado, com modelo, resultado e tempo.

---

## 4. Alinhamento ao conteúdo programático

O projeto é um tema livre — não corresponde a nenhum dos três temas sugeridos. O manual
admite essa escolha "desde que devidamente alinhada ao conteúdo programático do curso", e
admite expressamente o uso de "outro LLM à sua escolha", o que cobre o modelo local. O
alinhamento se dá nos seguintes pontos:

| Conteúdo | Como aparece no projeto |
|---|---|
| Engenharia de prompts | o prompt é a peça central, documentado na seção 2, com histórico de iterações e análise do seu limite |
| Saída estruturada | JSON Schema no campo `format`, com pós-processamento e validação de campos obrigatórios |
| Fluxo end-to-end | documento de entrada → OCR → LLM → regras → persistência → tela, funcionando ponta a ponta |
| Integração de serviços | três processos distintos (PHP, Python, Ollama) com contratos explícitos entre eles |
| Tratamento de falha do LLM | serviço indisponível, resposta não-JSON e campo obrigatório ausente, cada um com erro próprio e registro |
| Avaliação de resultado | comparação entre dois modelos com o mesmo prompt, medindo acerto e tempo |

A diferença em relação aos temas sugeridos é o uso de LLM **local** em vez de API, e de um
framework web em vez de ferramenta de automação. A escolha é justificada pelo próprio
problema: comprovantes de CNPJ são documentos de terceiros, e processá-los localmente
evita enviar dado de empresa para serviço externo.

---

## 5. Resultados e limitações

### Resultados

O sistema acerta os oito casos de teste, que cobrem um caminho cada:

| Empresa | Caso | Esperado | Obtido |
|---|---|---|---|
| 01 | tudo correto, PDF | aprovado | ✅ |
| 02 | tudo correto, foto de celular | aprovado | ✅ |
| 03 | menos de 2 anos de inscrição | reprovado (regra 5) | ✅ |
| 04 | CNPJ cadastrado diferente | reprovado (regra 2) | ✅ |
| 05 | data de inscrição diferente | reprovado (regra 4) | ✅ |
| 06 | situação `BAIXADA` | reprovado (regra 6) | ✅ |
| 07 | não é comprovante | reprovado (regra 1) | ✅ |
| 08 | inscrição não submetida | fora da avaliação | ✅ |

Tempo por avaliação, incluindo OCR, LLM e regras: 20 a 35 segundos com o modelo quente, 50
a 74 segundos na primeira chamada, que paga o carregamento do modelo na memória. O OCR
responde em 1,5 a 3,4 segundos; o restante é o LLM.

A suíte automatizada tem 44 testes, sendo 21 unitários das seis regras — sem OCR e sem
Ollama.

### Limitações

Registradas por honestidade metodológica:

- **A amostra é de sete documentos**, todos de companhias abertas, baixados do site da
  Receita no mesmo formato. É suficiente para exercitar cada regra uma vez; não é
  suficiente para afirmar acurácia.
- **O caso de imagem é foto de uma tela**, não de papel impresso: o OCR capturou elementos
  da janela do navegador. Falta o teste com inclinação, sombra e dobra de uma folha
  fotografada.
- **Nesse caso, o OCR perdeu o rótulo da data de inscrição** e a verificação passou usando
  a data de constituição, que coincide nestes documentos — ver caso C da seção 2.4. É a
  fragilidade mais concreta do sistema hoje, e ela está no OCR, não no LLM.
- **A avaliação é síncrona**: o lote de sete documentos prende a requisição por cerca de
  3,5 minutos. Proposital para a demonstração; em produção pediria fila.
- Sem autenticação, sem telas de cadastro de empresa e sem consulta à Receita Federal —
  todos fora do escopo declarado desde o início.

### O que o projeto mostra

Duas conclusões que não eram óbvias no começo:

1. **O tamanho do modelo importa mais que a instrução, a partir de certo ponto.** O mesmo
   prompt acerta 7/7 no `qwen3:4b` e 5/7 no `qwen3:1.7b`, e os erros caem exatamente onde
   o prompt avisa.
2. **O elo fraco do pipeline é o OCR, não o LLM.** Em todos os casos observados, quando o
   texto chegou íntegro o modelo extraiu corretamente. A única resposta frágil veio de um
   documento em que o OCR havia perdido o campo.

---

## Material complementar

| Onde | O que tem |
|---|---|
| `README.md` | visão geral e instruções para rodar |
| `specs/00` a `specs/07` | as oito especificações, escritas antes do código |
| `specs/notas-ambiente.md` | medições, comparação de modelos e as pegadinhas descobertas na prática |
