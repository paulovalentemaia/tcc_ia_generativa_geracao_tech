@extends('layouts.app')

@section('titulo', 'Detalhe da avaliação')

@section('conteudo')
    <p class="volta"><a href="{{ route('avaliacoes.index') }}">&larr; Voltar às avaliações</a></p>

    <h1>{{ $empresa->razao_social }}</h1>
    <p class="ajuda">
        Cadastro: {{ $empresa->cnpjFormatado() }} &middot;
        inscrição em {{ $empresa->data_inscricao_cnpj->format('d/m/Y') }} &middot;
        avaliada em {{ $avaliacao->created_at->format('d/m/Y H:i') }}
    </p>

    {{-- As secoes seguem a ordem do fluxo: e o que a tela explica. --}}

    <section class="etapa">
        <h2><span class="passo">1</span> Documento</h2>
        <div class="cartao">
            <p>
                <a href="{{ route('avaliacoes.comprovante', $empresa) }}" target="_blank" class="botao botao-secundario">
                    Abrir {{ basename($empresa->arquivo_comprovante) }}
                </a>
            </p>
            <p class="ajuda">
                Servido pelo Laravel a partir do disco privado: o arquivo nunca fica
                acessível por URL pública.
            </p>
        </div>
    </section>

    <section class="etapa">
        <h2><span class="passo">2</span> Texto lido pelo OCR</h2>
        <div class="cartao">
            @if (filled($avaliacao->texto_ocr))
                <pre class="bloco-ocr">{{ $avaliacao->texto_ocr }}</pre>
                <p class="ajuda">
                    O layout de duas colunas sai achatado: rótulo e valor quase nunca
                    ficam na mesma linha. É por isso que a leitura é feita por um LLM e
                    não por expressão regular.
                </p>
            @else
                <p class="ajuda">O OCR não chegou a produzir texto nesta avaliação.</p>
            @endif
        </div>
    </section>

    <section class="etapa">
        <h2><span class="passo">3</span> Dados extraídos pelo LLM</h2>
        <div class="cartao">
            @if ($avaliacao->dados_extraidos)
                <pre class="bloco-json">{{ json_encode($avaliacao->dados_extraidos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
            @else
                <p class="ajuda">A extração não chegou a devolver dados nesta avaliação.</p>
            @endif
            <p class="ajuda">
                Modelo: <strong>{{ $avaliacao->modelo_llm ?? 'não registrado' }}</strong>.
                O LLM apenas lê o documento e preenche os campos; não decide nada.
            </p>
        </div>
    </section>

    <section class="etapa">
        <h2><span class="passo">4</span> Verificações</h2>
        @if ($avaliacao->verificacoes)
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Regra</th>
                        <th>Cadastrado</th>
                        <th>No comprovante</th>
                        <th>Confere</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($avaliacao->verificacoes as $verificacao)
                        <tr>
                            <td>{{ $verificacao['regra'] }}</td>
                            <td>{{ $verificacao['rotulo'] }}</td>
                            <td>{{ $verificacao['cadastrado'] }}</td>
                            <td>{{ $verificacao['comprovante'] }}</td>
                            <td>
                                @if ($verificacao['ok'] === true)
                                    <span class="marca-ok">&#10003;</span>
                                @elseif ($verificacao['ok'] === false)
                                    <span class="marca-falha">&#10007;</span>
                                @else
                                    <span class="marca-neutra" title="Não avaliada">&mdash;</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <p class="ajuda">
                As regras são aplicadas em PHP, sem IA. Um traço quer dizer que a regra
                não chegou a ser avaliada — é o caso das regras 2 a 6 quando o documento
                nem é um comprovante.
            </p>
        @else
            <div class="cartao">
                <p class="ajuda">
                    A avaliação falhou antes de chegar às regras, então não há verificações
                    a mostrar. O motivo está no resultado, abaixo.
                </p>
            </div>
        @endif
    </section>

    <section class="etapa">
        <h2><span class="passo">5</span> Resultado</h2>
        <div class="cartao">
            <p>
                <span class="badge badge-{{ $avaliacao->resultado }}">{{ $avaliacao->resultado }}</span>
                <span class="ajuda">
                    em {{ $avaliacao->tempoEmSegundos() ?? '?' }} s (OCR + LLM + regras)
                </span>
            </p>
            <ul class="observacoes">
                @foreach ($avaliacao->observacoesComoLista() as $observacao)
                    <li>{{ $observacao }}</li>
                @endforeach
            </ul>
        </div>
    </section>

    @if ($anteriores->isNotEmpty())
        <section class="etapa">
            <h2>Avaliações anteriores desta empresa</h2>
            <table>
                <thead>
                    <tr>
                        <th>Data</th>
                        <th>Modelo</th>
                        <th>Resultado</th>
                        <th>Tempo</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($anteriores as $anterior)
                        <tr>
                            <td>{{ $anterior->created_at->format('d/m/Y H:i') }}</td>
                            <td>{{ $anterior->modelo_llm ?? '—' }}</td>
                            <td><span class="badge badge-{{ $anterior->resultado }}">{{ $anterior->resultado }}</span></td>
                            <td>{{ $anterior->tempoEmSegundos() ?? '?' }} s</td>
                            <td><a href="{{ route('avaliacoes.detalhe', $anterior) }}">Ver</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <p class="ajuda">
                Serve para comparar modelos: a mesma empresa avaliada com modelos
                diferentes aparece aqui lado a lado.
            </p>
        </section>
    @endif
@endsection
