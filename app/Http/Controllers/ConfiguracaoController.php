<?php

namespace App\Http\Controllers;

use App\Models\ConfiguracaoAvaliacao;
use App\Services\OllamaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ConfiguracaoController extends Controller
{
    public function __construct(private readonly OllamaService $ollama) {}

    public function edit(): View
    {
        $modelos = $this->ollama->modelos();

        return view('configuracoes.edit', [
            'config' => ConfiguracaoAvaliacao::atual(),
            'modelos' => $modelos,
            // Sem a lista, o select vira campo de texto livre: com o Ollama
            // parado a tela ainda tem que abrir e permitir salvar.
            'ollamaRespondeu' => $modelos !== [],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $modelos = $this->ollama->modelos();

        $dados = $request->validate([
            'data_fim_inscricoes' => ['required', 'date'],
            'anos_minimos' => ['required', 'integer', 'min:1', 'max:10'],
            'modelo_llm' => [
                'required',
                'string',
                // So restringe a lista quando ela existe. Com o Ollama parado,
                // exigir um modelo "disponivel" tornaria a tela insalvavel.
                ...($modelos === [] ? [] : [Rule::in($modelos)]),
            ],
            'ollama_url' => ['required', 'url'],
        ], [], [
            'data_fim_inscricoes' => 'data de fim das inscrições',
            'anos_minimos' => 'anos mínimos de inscrição',
            'modelo_llm' => 'modelo LLM',
            'ollama_url' => 'URL do Ollama',
        ]);

        // Tabela de registro unico: sempre update, nunca create.
        ConfiguracaoAvaliacao::atual()->update($dados);

        return redirect()
            ->route('configuracoes.edit')
            ->with('sucesso', 'Configurações salvas.');
    }

    public function testarConexao(Request $request): RedirectResponse
    {
        $modelo = (string) $request->input('modelo_llm', ConfiguracaoAvaliacao::atual()->modelo_llm);

        $resultado = $this->ollama->testar($modelo);

        $destino = redirect()->route('configuracoes.edit');

        if ($resultado['ok']) {
            return $destino->with('sucesso', "OK ({$resultado['ms']} ms) com o modelo {$modelo}.");
        }

        return $destino->with('erro', "O modelo {$modelo} não respondeu: {$resultado['erro']}");
    }
}
