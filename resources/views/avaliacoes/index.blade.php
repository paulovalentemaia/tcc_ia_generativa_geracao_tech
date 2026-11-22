@extends('layouts.app')

@section('titulo', 'Avaliações')

@section('conteudo')
    <h1>Avaliações</h1>

    <p class="barra-config">
        Fim das inscrições:
        <strong>{{ $config->data_fim_inscricoes?->format('d/m/Y') ?? 'não configurado' }}</strong>
        &middot; Mínimo: <strong>{{ $config->anos_minimos }} anos</strong>
        &middot; Modelo: <strong>{{ $config->modelo_llm }}</strong>
        <a href="{{ route('configuracoes.edit') }}">Alterar</a>
    </p>

    <form method="POST" action="{{ route('avaliacoes.todas') }}" class="acoes" data-avaliar>
        @csrf
        <button type="submit" class="botao">Avaliar todas</button>
        <span class="ajuda">
            Cada comprovante leva uns 25 segundos: OCR, leitura pelo LLM e as seis regras.
        </span>
    </form>

    <table>
        <thead>
            <tr>
                <th>Razão social</th>
                <th>CNPJ</th>
                <th>Data de inscrição</th>
                <th>Último resultado</th>
                <th>Data da avaliação</th>
                <th>Ações</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($empresas as $empresa)
                @php($ultima = $empresa->ultimaAvaliacao)
                <tr>
                    <td>{{ $empresa->razao_social }}</td>
                    <td>{{ $empresa->cnpjFormatado() }}</td>
                    <td>{{ $empresa->data_inscricao_cnpj->format('d/m/Y') }}</td>
                    <td>
                        @if ($ultima)
                            <span class="badge badge-{{ $ultima->resultado }}">{{ $ultima->resultado }}</span>
                        @else
                            &mdash;
                        @endif
                    </td>
                    <td>{{ $ultima?->created_at?->format('d/m/Y H:i') ?? '—' }}</td>
                    <td class="celula-acoes">
                        <form method="POST" action="{{ route('avaliacoes.avaliar', $empresa) }}" data-avaliar>
                            @csrf
                            <button type="submit" class="botao botao-pequeno">Avaliar</button>
                        </form>
                        @if ($ultima)
                            <a href="{{ route('avaliacoes.detalhe', $ultima) }}" class="botao botao-pequeno botao-secundario">
                                Ver detalhe
                            </a>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <p class="resumo">
        {{ $resumo['total'] }} empresas submetidas &middot;
        {{ $resumo['aprovadas'] }} aprovadas &middot;
        {{ $resumo['reprovadas'] }} reprovadas &middot;
        {{ $resumo['erros'] }} com erro
        @if ($resumo['nunca_avaliadas'] > 0)
            &middot; {{ $resumo['nunca_avaliadas'] }} nunca avaliadas
        @endif
    </p>

    {{-- A avaliacao e sincrona e demorada: sem isso o clique duplo enfileira
         uma segunda rodada de OCR e LLM. --}}
    <script>
        document.querySelectorAll('form[data-avaliar]').forEach(function (form) {
            form.addEventListener('submit', function () {
                form.querySelectorAll('button').forEach(function (botao) {
                    botao.disabled = true;
                    botao.textContent = 'Avaliando...';
                });
            });
        });
    </script>
@endsection
