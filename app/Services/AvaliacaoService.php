<?php

namespace App\Services;

use App\Exceptions\AvaliacaoException;
use App\Exceptions\ExtracaoException;
use App\Exceptions\OcrException;
use App\Models\Avaliacao;
use App\Models\ConfiguracaoAvaliacao;
use App\Models\Empresa;

/**
 * Orquestra o fluxo completo de uma avaliacao: OCR, extracao, regras e registro.
 *
 * E o unico lugar que conhece as tres etapas juntas. A avaliacao e sempre
 * gravada, inclusive quando algo falha no caminho, para que a tela de avaliacoes
 * possa mostrar o que aconteceu em vez de simplesmente nao ter registro.
 */
class AvaliacaoService
{
    public function __construct(
        private readonly OcrService $ocr,
        private readonly ExtracaoService $extracao,
        private readonly ValidacaoService $validacao,
    ) {}

    public function avaliar(Empresa $empresa): Avaliacao
    {
        $config = ConfiguracaoAvaliacao::atual();

        if ($config->data_fim_inscricoes === null) {
            throw AvaliacaoException::semDataFimInscricoes();
        }

        $inicio = microtime(true);

        $textoOcr = null;
        $bruto = null;
        $verificacoes = null;
        $modelo = $config->modelo_llm;

        try {
            $textoOcr = $this->ocr->extrairTexto($empresa->arquivo_comprovante);

            $extracao = $this->extracao->extrair($textoOcr);
            $bruto = $extracao['bruto'];
            $modelo = $extracao['modelo'];

            $validacao = $this->validacao->validar($empresa, $extracao['dados'], $config);
            $resultado = $validacao['resultado'];
            $observacoes = $validacao['observacoes'];
            $verificacoes = $validacao['verificacoes'];
        } catch (OcrException|ExtracaoException $e) {
            // O que ja foi obtido continua gravado: se o OCR passou e o LLM caiu,
            // o texto fica no registro e ajuda a entender a falha depois.
            $resultado = 'erro';
            $observacoes = [$e->getMessage()];
        }

        return $empresa->avaliacoes()->create([
            'resultado' => $resultado,
            'observacoes' => implode(PHP_EOL, $observacoes),
            'texto_ocr' => $textoOcr,
            'dados_extraidos' => $bruto,
            'verificacoes' => $verificacoes,
            'modelo_llm' => $modelo,
            'tempo_ms' => (int) round((microtime(true) - $inicio) * 1000),
        ]);
    }
}
