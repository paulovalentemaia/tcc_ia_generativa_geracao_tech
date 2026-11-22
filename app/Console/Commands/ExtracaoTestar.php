<?php

namespace App\Console\Commands;

use App\Exceptions\ExtracaoException;
use App\Exceptions\OcrException;
use App\Models\Empresa;
use App\Services\ExtracaoService;
use App\Services\OcrService;
use Illuminate\Console\Command;

class ExtracaoTestar extends Command
{
    protected $signature = 'extracao:testar {empresa_id : ID da empresa}';

    protected $description = 'Roda OCR e extracao com o LLM e imprime o JSON';

    public function handle(OcrService $ocr, ExtracaoService $extracao): int
    {
        $empresa = Empresa::query()->find($this->argument('empresa_id'));

        if ($empresa === null) {
            $this->error("Empresa {$this->argument('empresa_id')} não encontrada.");

            return self::FAILURE;
        }

        $this->line("Empresa:  {$empresa->razao_social}");
        $this->line("Cadastro: {$empresa->cnpjFormatado()} / "
            . $empresa->data_inscricao_cnpj->format('d/m/Y'));
        $this->newLine();

        try {
            $inicioOcr = microtime(true);
            $texto = $ocr->extrairTexto($empresa->arquivo_comprovante);
            $msOcr = (int) round((microtime(true) - $inicioOcr) * 1000);

            $resultado = $extracao->extrair($texto);
        } catch (OcrException|ExtracaoException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line('Modelo: ' . $resultado['modelo']);
        $this->line(sprintf('Tempo:  OCR %d ms + LLM %d ms', $msOcr, $resultado['tempo_ms']));
        $this->newLine();

        $this->line('-- bruto do modelo --');
        $this->line($this->comoJson($resultado['bruto']));
        $this->newLine();

        $this->line('-- pos-processado --');
        $this->line($this->comoJson($resultado['dados']));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $dados
     */
    private function comoJson(array $dados): string
    {
        return json_encode($dados, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ?: '(não foi possível formatar)';
    }
}
