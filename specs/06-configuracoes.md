# 06 — Tela de configurações

## Objetivo
Permitir editar os parâmetros da avaliação sem mexer em código ou `.env`.

## Layout geral (vale também para a spec 07)
- Layout Blade único (`layouts/app.blade.php`) com menu superior:
  - **Avaliações** (página inicial)
  - **Configurações**
- Visual simples e limpo (Tailwind, se já disponível no projeto).
- Mensagens de sucesso/erro via flash na sessão.

## Rotas
- `GET /configuracoes` → formulário
- `PUT /configuracoes` → salvar

## Campos do formulário
| Campo | Tipo | Validação |
|---|---|---|
| Data de fim das inscrições | date | obrigatória |
| Anos mínimos de inscrição | número | obrigatório, inteiro, 1 a 10 |
| Modelo LLM | select | obrigatório, um dos modelos disponíveis |
| URL do Ollama | texto | obrigatória, URL válida |

## Lista de modelos
- Buscar os modelos instalados em `GET {ollama_url}/api/tags` e preencher o select.
- Se o Ollama estiver inacessível: mostrar aviso "Ollama não respondeu" e um campo de texto livre no lugar do select.

## Extras
- Botão **Testar conexão**: chama o Ollama com o modelo selecionado e mostra "OK (X ms)" ou o erro.
- Texto de ajuda abaixo da data: "A regra de tempo mínimo de inscrição no CNPJ é calculada até esta data."

## Critérios de aceite
- [ ] O formulário abre preenchido com os valores atuais.
- [ ] Salvar altera o registro único (não cria um novo).
- [ ] O select lista os modelos instalados no Ollama.
- [ ] Com o Ollama parado, a tela abre normalmente, com aviso.
- [ ] "Testar conexão" mostra sucesso com o Ollama ligado e erro com ele parado.
- [ ] Alterar a data e reavaliar muda o resultado da regra 5.

## Fora de escopo
- Edição do prompt pela tela, histórico de configurações, autenticação.
