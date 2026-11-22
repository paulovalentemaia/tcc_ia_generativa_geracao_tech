<?php

namespace App\Services;

use App\Models\ConfiguracaoAvaliacao;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Conversa com o Ollama para o que a tela de configuracoes precisa saber:
 * quais modelos estao instalados e se o escolhido responde.
 *
 * Fica fora do ExtracaoService de proposito. Aquele e a peca de IA generativa do
 * trabalho, com prompt, schema e pos-processamento; estas duas chamadas sao de
 * infraestrutura e nao tem nada a dizer sobre comprovantes.
 */
class OllamaService
{
    /**
     * Curto na marra: a tela nao pode ficar pendurada esperando um Ollama que
     * nao vai responder. A extracao, que e o trabalho de verdade, usa 120 s.
     */
    private const TEMPO_LIMITE_SEGUNDOS = 10;

    /**
     * Nomes dos modelos instalados. Devolve vazio quando o Ollama nao responde —
     * a tela decide o que fazer com isso, em vez de quebrar.
     *
     * @return string[]
     */
    public function modelos(): array
    {
        $config = ConfiguracaoAvaliacao::atual();

        try {
            $resposta = Http::timeout(self::TEMPO_LIMITE_SEGUNDOS)
                ->get($this->url($config->ollama_url, '/api/tags'));
        } catch (Throwable) {
            return [];
        }

        if ($resposta->failed()) {
            return [];
        }

        $modelos = $resposta->json('models');

        // A URL do Ollama e editavel pela tela. Se ela apontar para outro
        // servico que responda 200 com outro JSON, isto aqui precisa devolver
        // vazio e cair no aviso de "nao respondeu" — nao estourar a tela.
        if (! is_array($modelos)) {
            return [];
        }

        $nomes = [];

        foreach ($modelos as $modelo) {
            if (is_array($modelo) && isset($modelo['name']) && is_string($modelo['name'])) {
                $nomes[] = $modelo['name'];
            }
        }

        sort($nomes);

        return $nomes;
    }

    /**
     * Pergunta qualquer coisa ao modelo, so para ver se ele responde.
     *
     * @return array{ok: bool, ms: int, erro: ?string}
     */
    public function testar(string $modelo): array
    {
        $config = ConfiguracaoAvaliacao::atual();
        $inicio = microtime(true);

        try {
            $resposta = Http::timeout(self::TEMPO_LIMITE_SEGUNDOS * 3)
                ->post($this->url($config->ollama_url, '/api/chat'), [
                    'model' => $modelo,
                    'stream' => false,
                    'think' => false,
                    'keep_alive' => '5m',
                    'messages' => [
                        ['role' => 'user', 'content' => 'Responda apenas: ok'],
                    ],
                ]);
        } catch (Throwable $e) {
            return $this->falha($inicio, $e->getMessage());
        }

        if ($resposta->failed()) {
            return $this->falha($inicio, "HTTP {$resposta->status()}: " . mb_strimwidth(trim($resposta->body()), 0, 150, '...'));
        }

        return [
            'ok' => true,
            'ms' => $this->decorrido($inicio),
            'erro' => null,
        ];
    }

    private function url(string $base, string $caminho): string
    {
        return rtrim($base, '/') . $caminho;
    }

    /**
     * @return array{ok: bool, ms: int, erro: ?string}
     */
    private function falha(float $inicio, string $erro): array
    {
        return ['ok' => false, 'ms' => $this->decorrido($inicio), 'erro' => $erro];
    }

    private function decorrido(float $inicio): int
    {
        return (int) round((microtime(true) - $inicio) * 1000);
    }
}
