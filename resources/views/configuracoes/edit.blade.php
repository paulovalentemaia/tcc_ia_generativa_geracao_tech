@extends('layouts.app')

@section('titulo', 'Configurações')

@section('conteudo')
    <h1>Configurações da avaliação</h1>

    @unless ($ollamaRespondeu)
        <p class="aviso aviso-atencao">
            Ollama não respondeu em {{ $config->ollama_url }}. A lista de modelos não
            pode ser carregada, então o modelo pode ser digitado abaixo.
        </p>
    @endunless

    <div class="cartao">
        <form method="POST" action="{{ route('configuracoes.update') }}">
            @csrf
            @method('PUT')

            <div class="campo">
                <label for="data_fim_inscricoes">Data de fim das inscrições</label>
                <input type="date"
                       id="data_fim_inscricoes"
                       name="data_fim_inscricoes"
                       value="{{ old('data_fim_inscricoes', $config->data_fim_inscricoes?->format('Y-m-d')) }}"
                       required>
                <span class="ajuda">
                    A regra de tempo mínimo de inscrição no CNPJ é calculada até esta data.
                </span>
                @error('data_fim_inscricoes')
                    <span class="erro-campo">{{ $message }}</span>
                @enderror
            </div>

            <div class="campo">
                <label for="anos_minimos">Anos mínimos de inscrição</label>
                <input type="number"
                       id="anos_minimos"
                       name="anos_minimos"
                       min="1"
                       max="10"
                       value="{{ old('anos_minimos', $config->anos_minimos) }}"
                       required>
                @error('anos_minimos')
                    <span class="erro-campo">{{ $message }}</span>
                @enderror
            </div>

            <div class="campo">
                <label for="modelo_llm">Modelo LLM</label>
                @if ($ollamaRespondeu)
                    <select id="modelo_llm" name="modelo_llm" required>
                        @foreach ($modelos as $modelo)
                            <option value="{{ $modelo }}"
                                @selected($modelo === old('modelo_llm', $config->modelo_llm))>
                                {{ $modelo }}
                            </option>
                        @endforeach
                    </select>
                @else
                    <input type="text"
                           id="modelo_llm"
                           name="modelo_llm"
                           value="{{ old('modelo_llm', $config->modelo_llm) }}"
                           required>
                @endif
                @error('modelo_llm')
                    <span class="erro-campo">{{ $message }}</span>
                @enderror
            </div>

            <div class="campo">
                <label for="ollama_url">URL do Ollama</label>
                <input type="text"
                       id="ollama_url"
                       name="ollama_url"
                       value="{{ old('ollama_url', $config->ollama_url) }}"
                       required>
                <span class="ajuda">
                    Dentro do Docker os serviços se enxergam pelo nome, não por localhost.
                </span>
                @error('ollama_url')
                    <span class="erro-campo">{{ $message }}</span>
                @enderror
            </div>

            <div class="acoes">
                <button type="submit" class="botao">Salvar</button>
            </div>
        </form>
    </div>

    {{-- Fora do formulario de cima para nao salvar nada ao testar a conexao. --}}
    <div class="acoes">
        <form method="POST" action="{{ route('configuracoes.testar') }}">
            @csrf
            <input type="hidden" name="modelo_llm" value="{{ $config->modelo_llm }}">
            <button type="submit" class="botao botao-secundario"
                    onclick="this.disabled = true; this.textContent = 'Testando...'; this.form.submit();">
                Testar conexão
            </button>
        </form>
        <span class="ajuda">
            Chama o modelo {{ $config->modelo_llm }} salvo e mostra o tempo de resposta.
        </span>
    </div>
@endsection
