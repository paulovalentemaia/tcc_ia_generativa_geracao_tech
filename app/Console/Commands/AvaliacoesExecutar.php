<?php

namespace App\Console\Commands;

use App\Exceptions\AvaliacaoException;
use App\Models\Avaliacao;
use App\Models\Empresa;
use App\Services\AvaliacaoService;
use Illuminate\Console\Command;

class AvaliacoesExecutar extends Command
{
    protected $signature = 'avaliacoes:executar {empresa_id? : ID da empresa; sem ele, avalia todas as submetidas}';

    protected $description = 'Avalia os comprovantes e grava o resultado';

    public function handle(AvaliacaoService $avaliacao): int
    {
        $empresas = $this->empresas();

        if ($empresas === null) {
            return self::FAILURE;
        }

        if ($empresas === []) {
            $this->warn('Nenhuma empresa submetida para avaliar.');

            return self::SUCCESS;
        }

        $linhas = [];

        foreach ($empresas as $empresa) {
            $this->line("Avaliando {$empresa->razao_social}...");

            try {
                $registro = $avaliacao->avaliar($empresa);
            } catch (AvaliacaoException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }

            $linhas[] = [
                $empresa->id,
                mb_strimwidth($empresa->razao_social, 0, 30, '...'),
                $registro->resultado,
                $registro->tempoEmSegundos() . ' s',
                $this->observacoes($registro),
            ];
        }

        $this->newLine();
        $this->table(['#', 'Empresa', 'Resultado', 'Tempo', 'Observações'], $linhas);

        return self::SUCCESS;
    }

    /**
     * @return Empresa[]|null  null quando o ID informado nao existe
     */
    private function empresas(): ?array
    {
        $id = $this->argument('empresa_id');

        if ($id === null) {
            return Empresa::submetidas()->orderBy('id')->get()->all();
        }

        $empresa = Empresa::query()->find($id);

        if ($empresa === null) {
            $this->error("Empresa {$id} não encontrada.");

            return null;
        }

        // Com o ID explicito a empresa e avaliada mesmo em 'criando': o filtro de
        // situacao existe para a execucao em lote, nao para bloquear um teste.
        return [$empresa];
    }

    /**
     * Na tabela cabe pouco, entao sai a primeira observacao e a contagem do resto.
     */
    private function observacoes(Avaliacao $registro): string
    {
        $lista = $registro->observacoesComoLista();

        if ($lista === []) {
            return '';
        }

        $primeira = mb_strimwidth($lista[0], 0, 60, '...');
        $restantes = count($lista) - 1;

        return $restantes > 0 ? "{$primeira} (+{$restantes})" : $primeira;
    }
}
