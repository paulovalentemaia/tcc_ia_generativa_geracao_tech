# Avaliação de comprovantes de CNPJ com IA generativa

Sistema que confere automaticamente **Comprovantes de Inscrição e de Situação Cadastral
de CNPJ** enviados por empresas numa seleção, usando OCR e um LLM que rodam inteiramente
na máquina local — sem API paga, sem chave e sem enviar documento de ninguém para fora.

Trabalho de conclusão de curso. O problema é real: depois do fim das inscrições, alguém
precisa abrir cada comprovante e comparar o que está nele com o que a empresa declarou.
Aqui isso é feito em cerca de 25 segundos por documento, com o motivo de cada reprovação
registrado.

## Como funciona

```
Comprovante (PDF ou JPG)
   ↓
Indra OCR (Python, CPU)      → texto corrido
   ↓
LLM local via Ollama         → JSON com os cinco campos
   ↓
Regras em PHP                → aprovado / reprovado + observações
   ↓
Tabela avaliacoes + tela de detalhe
```

Cada peça faz uma coisa só: **o OCR lê, o LLM entende, o PHP decide.**

Essa fronteira é a decisão central do projeto. O LLM nunca aprova nem reprova ninguém:
ele só recebe o texto do OCR e preenche campos. Quem decide é o
[`ValidacaoService`](app/Services/ValidacaoService.php), PHP determinístico e testável sem
OCR e sem LLM — e é por isso que as seis regras têm teste unitário próprio.

### Por que um LLM e não expressão regular

O comprovante é de duas colunas e o OCR achata o layout. O valor de um campo pode sair
antes do rótulo, depois dele ou na mesma linha:

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

Há três datas competindo (inscrição, constituição e emissão) e dois nomes parecidos (nome
empresarial e nome fantasia). Distinguir exige entender o documento, não casar padrão. O
OCR ainda erra os rótulos — `NÚMERO DE INSCRIÇÃO` saiu como `TNUMERO DE INSCRIÇÃO` em dois
dos sete documentos —, então nenhuma regex pode depender da grafia exata.

## As seis regras

| # | Regra | Reprova quando |
|---|---|---|
| 1 | É um comprovante de CNPJ | o arquivo é outro tipo de documento |
| 2 | CNPJ confere | difere do cadastrado |
| 3 | Razão social confere | difere da cadastrada |
| 4 | Data de inscrição confere | difere da declarada |
| 5 | Tempo mínimo de inscrição | menos de N anos (padrão 2) no fim das inscrições |
| 6 | Situação cadastral | diferente de `ATIVA` |

A regra 1 reprova de imediato e as outras não são avaliadas: conferir o CNPJ de um
contrato não diria nada. A comparação de razão social normaliza maiúsculas, acentos e
pontuação e aceita 90% de similaridade, o que absorve erros de OCR e as variações de
`S/A`, `S.A.` e `SA` sem deixar passar outra empresa. O nome fantasia não é verificado.

## Rodando

Pré-requisito: Docker. Tudo roda em container — não é preciso ter PHP, Python nem Node na
máquina.

```sh
cp .env.example .env
UID=$(id -u) GID=$(id -g) docker compose up -d
docker compose exec app php artisan key:generate

# o MySQL leva ~1 min para inicializar no primeiro up
until docker compose exec -T mysql mysqladmin ping -h 127.0.0.1 -utcc -ptcc --silent; do sleep 5; done

docker compose exec ollama ollama pull qwen3:4b
docker compose exec app php artisan migrate:fresh --seed
```

O `.env.example` já vem com o banco e o OCR apontando para dentro do Docker
(`DB_HOST=mysql`, `OCR_PYTHON=/opt/indra/bin/python`), então não precisa editar nada.

O sistema fica em **http://localhost:8000**.

Os comprovantes de teste não estão no repositório: vão em
`storage/app/private/comprovantes/`, com os nomes que o
[`EmpresaSeeder`](database/seeders/EmpresaSeeder.php) referencia.

### Pela linha de comando

```sh
docker compose exec app php artisan avaliacoes:executar      # avalia todas as submetidas
docker compose exec app php artisan avaliacoes:executar 3     # uma empresa
docker compose exec app php artisan ocr:testar 3              # só o texto do OCR
docker compose exec app php artisan extracao:testar 3         # OCR + LLM, imprime o JSON
docker compose exec app php artisan test                      # 44 testes
```

### Pelas telas

- **Avaliações** (`/`) — as empresas submetidas com o último resultado, botões de avaliar
  uma ou todas, e resumo no rodapé.
- **Detalhe** — percorre o fluxo na ordem: o documento, o texto que o OCR leu, o JSON que
  o LLM devolveu, a tabela das seis regras com valor cadastrado e valor do comprovante
  lado a lado, e o resultado. É a tela que explica o processo.
- **Configurações** — data de fim das inscrições, anos mínimos, modelo LLM (lista os
  instalados no Ollama) e URL do Ollama, com botão de testar a conexão.

Trocar o modelo pela tela e reavaliar é o que permite comparar modelos: o detalhe lista as
avaliações anteriores da mesma empresa.

## Casos de teste

Oito empresas mockadas por seeder, cada uma exercitando um caminho:

| Empresa | Caso | Esperado |
|---|---|---|
| 01 | tudo correto, PDF | aprovado |
| 02 | tudo correto, foto de celular | aprovado |
| 03 | menos de 2 anos de inscrição | reprovado (regra 5) |
| 04 | CNPJ cadastrado diferente do comprovante | reprovado (regra 2) |
| 05 | data de inscrição cadastrada diferente | reprovado (regra 4) |
| 06 | situação `BAIXADA` | reprovado (regra 6) |
| 07 | não é comprovante (Fato Relevante) | reprovado (regra 1) |
| 08 | situação `criando` | não entra na avaliação |

A empresa 07 usa um Fato Relevante da própria Lojas Renner, documento público que traz o
CNPJ correto no cabeçalho. A escolha é deliberada: não basta o modelo achar um CNPJ no
texto e concluir que é comprovante — ele precisa reconhecer o **tipo** do documento.

## Resultados

O sistema acerta os 8 casos. O achado mais interessante é a diferença entre modelos:

| Modelo | Acerto | Tempo médio |
|---|---|---|
| `qwen3:4b` | **7/7** | 23 s |
| `qwen3:1.7b` | 5/7 | 13 s |

Os dois erros do modelo menor são exatamente as duas armadilhas do documento: extraiu
`PETROBRAS` (o nome fantasia) no lugar do nome empresarial, e pegou a data de emissão no
lugar da data de inscrição. O prompt alerta sobre as duas de forma explícita, com exemplo.
Não é falha de formato nem de JSON inválido — é compreensão do documento, e o modelo menor
não sustenta a instrução. Como os dois erros reprovam empresas regulares, o `qwen3:4b` é o
modelo oficial.

Medições completas em [`specs/notas-ambiente.md`](specs/notas-ambiente.md).

## Limitações

Registradas por honestidade metodológica:

- **A amostra é de 7 documentos**, todos de companhias abertas e baixados do site da
  Receita no mesmo formato. É suficiente para exercitar cada regra, não para afirmar
  acurácia.
- **O caso de imagem é foto de uma tela**, não de papel impresso. Falta o teste com
  inclinação, sombra e dobra de uma folha fotografada.
- **Nesse caso de imagem, o OCR perdeu o rótulo `DATA DE INSCRIÇÃO NO CNPJ` e seu valor.**
  A avaliação passou porque sobrou a `DATA DE CONSTITUIÇÃO`, que nestes oito documentos
  coincide com a de inscrição. Num documento em que as duas divergissem, essa qualidade de
  imagem daria resposta errada. Está detalhado nas notas de ambiente.
- A avaliação é **síncrona**: avaliar sete documentos prende a requisição por uns 3,5
  minutos. Para a demonstração é proposital; em produção pediria fila.
- Sem autenticação, sem telas de cadastro, sem consulta à Receita. Tudo fora de escopo
  declarado na [spec 00](specs/00-visao-geral.md).

## Organização do código

```
app/Services/          OcrService, ExtracaoService, ValidacaoService,
                       AvaliacaoService, OllamaService
app/Exceptions/        uma exceção por etapa do fluxo
scripts/ocr/ocr.py     ponte para o Indra; contrato é JSON no stdout
specs/                 as oito especificações, em ordem
docs/                  documentação de entrega e roteiro da demonstração
tests/                 21 testes das regras + 23 das telas e do fluxo
```

As [especificações](specs/) foram escritas antes do código e são a fonte da verdade.
`specs/notas-ambiente.md` registra o que mudou na prática e por quê — é onde estão as
pegadinhas descobertas na marra.

A [documentação do projeto](docs/documentacao-do-projeto.md) traz o problema, a
**engenharia de prompts** — com o prompt completo, as variáveis, os exemplos de saída e o
histórico de iterações — e a arquitetura da solução.

## Stack

| Peça | Versão |
|---|---|
| Laravel | 13 |
| PHP | 8.3 |
| MySQL | 8.4 |
| Python | 3.11 |
| Indra OCR | 1.1.2 |
| Ollama | `qwen3:4b` |

Sem build de frontend: as telas são Blade com uma folha de CSS escrita à mão. O container
não tem Node, e depender de CDN numa apresentação seria risco desnecessário.
