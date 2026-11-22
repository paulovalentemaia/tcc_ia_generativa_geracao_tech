<?php

namespace App\Http\Controllers;

use App\Exceptions\AvaliacaoException;
use App\Models\Avaliacao;
use App\Models\ConfiguracaoAvaliacao;
use App\Models\Empresa;
use App\Services\AvaliacaoService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AvaliacaoController extends Controller
{
    public function __construct(private readonly AvaliacaoService $avaliacao) {}

    public function index(): View
    {
        $empresas = Empresa::submetidas()
            ->with('ultimaAvaliacao')
            ->orderBy('id')
            ->get();

        return view('avaliacoes.index', [
            'empresas' => $empresas,
            'config' => ConfiguracaoAvaliacao::atual(),
            'resumo' => $this->resumo($empresas),
        ]);
    }

    public function avaliar(Empresa $empresa): RedirectResponse
    {
        try {
            $registro = $this->avaliacao->avaliar($empresa);
        } catch (AvaliacaoException $e) {
            return redirect()->route('avaliacoes.index')->with('erro', $e->getMessage());
        }

        return redirect()
            ->route('avaliacoes.index')
            ->with('sucesso', "{$empresa->razao_social}: {$registro->resultado}.");
    }

    public function avaliarTodas(): RedirectResponse
    {
        // Avaliacao sincrona, como a spec pede: sete documentos a uns 25 s cada
        // passam folgado do limite padrao do PHP.
        set_time_limit(0);

        $empresas = Empresa::submetidas()->orderBy('id')->get();

        foreach ($empresas as $empresa) {
            try {
                $this->avaliacao->avaliar($empresa);
            } catch (AvaliacaoException $e) {
                return redirect()->route('avaliacoes.index')->with('erro', $e->getMessage());
            }
        }

        return redirect()
            ->route('avaliacoes.index')
            ->with('sucesso', "{$empresas->count()} empresas avaliadas.");
    }

    public function detalhe(Avaliacao $avaliacao): View
    {
        $avaliacao->load('empresa');

        return view('avaliacoes.detalhe', [
            'avaliacao' => $avaliacao,
            'empresa' => $avaliacao->empresa,
            'anteriores' => $avaliacao->empresa
                ->avaliacoes()
                ->whereKeyNot($avaliacao->getKey())
                ->latest('id')
                ->get(),
        ]);
    }

    /**
     * Serve o comprovante pelo Laravel, nao pelo servidor web: os arquivos ficam
     * em storage/app/private e nunca devem virar URL publica.
     */
    public function comprovante(Empresa $empresa): StreamedResponse|Response
    {
        $disco = Storage::disk('local');

        if (! $disco->exists($empresa->arquivo_comprovante)) {
            abort(404, 'Comprovante não encontrado no disco.');
        }

        return $disco->response(
            $empresa->arquivo_comprovante,
            basename($empresa->arquivo_comprovante),
            ['Content-Disposition' => 'inline'],
        );
    }

    /**
     * @param  Collection<int, Empresa>  $empresas
     * @return array<string, int>
     */
    private function resumo(Collection $empresas): array
    {
        $resultados = $empresas
            ->map(fn (Empresa $empresa): ?string => $empresa->ultimaAvaliacao?->resultado)
            ->filter();

        return [
            'total' => $empresas->count(),
            'aprovadas' => $resultados->filter(fn (string $r): bool => $r === 'aprovado')->count(),
            'reprovadas' => $resultados->filter(fn (string $r): bool => $r === 'reprovado')->count(),
            'erros' => $resultados->filter(fn (string $r): bool => $r === 'erro')->count(),
            'nunca_avaliadas' => $empresas->count() - $resultados->count(),
        ];
    }
}
