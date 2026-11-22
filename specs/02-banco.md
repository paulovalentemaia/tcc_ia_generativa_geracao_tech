# 02 — Banco, models e seeders

## Objetivo
Criar as tabelas e popular com empresas mockadas que cobrem todos os casos de teste.

## Migrations

### `empresas`
| Coluna | Tipo |
|---|---|
| id | bigIncrements |
| razao_social | string(255) |
| nome_fantasia | string(255), nullable |
| cnpj | char(14), unique — só números |
| data_inscricao_cnpj | date |
| arquivo_comprovante | string — caminho relativo, ex.: `comprovantes/empresa_01.pdf` |
| situacao | enum(`criando`, `submetido`), default `criando` |
| timestamps | |

### `avaliacoes`
| Coluna | Tipo |
|---|---|
| id | bigIncrements |
| empresa_id | foreignId → empresas, cascadeOnDelete |
| resultado | enum(`aprovado`, `reprovado`, `erro`) |
| observacoes | text, nullable |
| texto_ocr | longText, nullable |
| dados_extraidos | json, nullable |
| verificacoes | json, nullable |
| modelo_llm | string, nullable |
| tempo_ms | unsignedInteger, nullable |
| timestamps | |

Relação 1:N — uma empresa pode ter várias avaliações (permite comparar modelos).

`verificacoes` foi acrescentada depois, por migration própria, ao implementar a spec 07:
guarda o resultado de cada uma das seis regras (valor cadastrado, valor do comprovante,
✓/✗) como ele valia no momento da avaliação. A tela de detalhe monta a tabela de regras a
partir dela. Recalcular na hora de exibir seria mais simples, mas os ✓/✗ passariam a
divergir do `resultado` já gravado assim que a configuração mudasse — justamente o que a
spec 06 pede para demonstrar.

### `configuracoes_avaliacao`
| Coluna | Tipo | Padrão |
|---|---|---|
| id | bigIncrements | |
| data_fim_inscricoes | date | |
| anos_minimos | unsignedTinyInteger | 2 |
| modelo_llm | string | `qwen3:4b` |
| ollama_url | string | `http://ollama:11434` (nome do serviço no Docker) |
| timestamps | | |

Tabela de **registro único**: o sistema sempre usa o primeiro registro.

## Models
- `Empresa`: `hasMany(Avaliacao)`, `hasOne` última avaliação (`latestOfMany`), casts de data, scope `submetidas()`.
- `Avaliacao`: `belongsTo(Empresa)`, cast `dados_extraidos` → `array`.
- `ConfiguracaoAvaliacao`: método estático `atual()` que retorna o registro único (cria com padrões se não existir).

## Arquivos
- Comprovantes em `storage/app/private/comprovantes/` (disco `local`, nunca público).
- Os arquivos reais são colocados manualmente pelo usuário; o seeder só referencia os nomes.

## Seeders
`ConfiguracaoAvaliacaoSeeder`: `data_fim_inscricoes` = `2026-11-30`, demais com padrão.

`EmpresaSeeder` — todas com situação `submetido`, exceto a última:

| Arquivo | Caso | Resultado esperado |
|---|---|---|
| empresa_01.pdf | Tudo correto | aprovado |
| empresa_02.jpeg | Tudo correto, foto de celular | aprovado |
| empresa_03.pdf | Menos de 2 anos na data de fim | reprovado |
| empresa_04.pdf | CNPJ cadastrado diferente do comprovante | reprovado |
| empresa_05.pdf | Data de inscrição cadastrada diferente | reprovado |
| empresa_06.pdf | Situação BAIXADA ou INAPTA | reprovado |
| empresa_07.pdf | Arquivo não é comprovante de CNPJ | reprovado |
| empresa_08.pdf | Situação `criando` | não aparece na avaliação |

Os dados cadastrados (razão social, CNPJ, data) devem ser preenchidos pelo usuário de acordo com os documentos reais, deixando a divergência proposital em cada caso.

## Critérios de aceite
- [ ] `php artisan migrate:fresh --seed` roda sem erro.
- [ ] `Empresa::submetidas()->count()` retorna 7.
- [ ] `ConfiguracaoAvaliacao::atual()` retorna a data 2026-11-30 e o modelo `qwen3:4b`.
- [ ] CNPJs gravados com 14 dígitos, sem máscara.

## Fora de escopo
- Factories com dados aleatórios.
