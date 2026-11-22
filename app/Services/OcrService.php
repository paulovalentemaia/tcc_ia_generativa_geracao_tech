<?php

namespace App\Services;

use App\Exceptions\OcrException;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use JsonException;

/**
 * Chama o script Python do Indra e devolve o texto do comprovante.
 *
 * O Laravel e o Python moram no mesmo container justamente para isto: a
 * comunicacao e por processo, e o contrato entre os dois e o JSON do stdout.
 */
class OcrService
{
    private const TEMPO_LIMITE_SEGUNDOS = 120;

    public function extrairTexto(string $caminhoRelativo): string
    {
        $disco = Storage::disk('local');

        if (! $disco->exists($caminhoRelativo)) {
            throw OcrException::arquivoNaoEncontrado($caminhoRelativo);
        }

        $resultado = Process::timeout(self::TEMPO_LIMITE_SEGUNDOS)->run([
            config('ocr.python'),
            base_path(config('ocr.script')),
            $disco->path($caminhoRelativo),
        ]);

        if ($resultado->failed()) {
            // O script devolve JSON tambem quando recusa o arquivo, entao vale
            // tentar ler a mensagem antes de cair no stderr cru.
            $dados = $this->decodificar($resultado->output(), falharSeInvalido: false);

            if (is_array($dados) && isset($dados['erro'])) {
                throw OcrException::recusado((string) $dados['erro']);
            }

            throw OcrException::processoFalhou(
                $resultado->errorOutput() ?: $resultado->output()
            );
        }

        $dados = $this->decodificar($resultado->output(), falharSeInvalido: true);

        if (($dados['ok'] ?? false) !== true) {
            throw OcrException::recusado((string) ($dados['erro'] ?? 'motivo nao informado'));
        }

        $texto = trim((string) ($dados['texto'] ?? ''));

        if ($texto === '') {
            throw OcrException::textoVazio();
        }

        return $texto;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodificar(string $saida, bool $falharSeInvalido): ?array
    {
        try {
            $dados = json_decode(trim($saida), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            if ($falharSeInvalido) {
                throw OcrException::respostaInvalida($saida);
            }

            return null;
        }

        if (! is_array($dados)) {
            if ($falharSeInvalido) {
                throw OcrException::respostaInvalida($saida);
            }

            return null;
        }

        return $dados;
    }
}
