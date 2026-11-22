<?php

namespace App\Console\Commands;

use App\Exceptions\OcrException;
use App\Models\Empresa;
use App\Services\OcrService;
use Illuminate\Console\Command;

class OcrTestar extends Command
{
    protected $signature = 'ocr:testar {empresa_id : ID da empresa}';

    protected $description = 'Extrai e imprime o texto do comprovante de uma empresa';

    public function handle(OcrService $ocr): int
    {
        $empresa = Empresa::query()->find($this->argument('empresa_id'));

        if ($empresa === null) {
            $this->error("Empresa {$this->argument('empresa_id')} não encontrada.");

            return self::FAILURE;
        }

        $this->line("Empresa: {$empresa->razao_social}");
        $this->line("Arquivo: {$empresa->arquivo_comprovante}");
        $this->newLine();

        $inicio = microtime(true);

        try {
            $texto = $ocr->extrairTexto($empresa->arquivo_comprovante);
        } catch (OcrException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $ms = (int) round((microtime(true) - $inicio) * 1000);

        $this->line($texto);
        $this->newLine();
        $this->info(sprintf(
            '%d linhas, %d caracteres, %d ms',
            substr_count($texto, "\n") + 1,
            mb_strlen($texto),
            $ms
        ));

        return self::SUCCESS;
    }
}
