<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('titulo', 'Avaliação de comprovantes')</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
    <header class="topo">
        <div class="topo-interno">
            <span class="marca">Avaliação de comprovantes de CNPJ</span>
            <nav class="menu">
                <a href="{{ route('avaliacoes.index') }}"
                   class="{{ request()->routeIs('avaliacoes.*') ? 'ativo' : '' }}">Avaliações</a>
                <a href="{{ route('configuracoes.edit') }}"
                   class="{{ request()->routeIs('configuracoes.*') ? 'ativo' : '' }}">Configurações</a>
            </nav>
        </div>
    </header>

    <main class="conteudo">
        @if (session('sucesso'))
            <p class="aviso aviso-sucesso">{{ session('sucesso') }}</p>
        @endif

        @if (session('erro'))
            <p class="aviso aviso-erro">{{ session('erro') }}</p>
        @endif

        @yield('conteudo')
    </main>
</body>
</html>
