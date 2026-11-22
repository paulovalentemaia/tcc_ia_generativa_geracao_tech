<?php

namespace App\Services;

use App\Exceptions\ExtracaoException;
use App\Models\ConfiguracaoAvaliacao;
use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use JsonException;
use Throwable;

/**
 * Le o texto do OCR e devolve os campos do comprovante em JSON estruturado.
 *
 * Esta e a peca de IA generativa do projeto: o LLM nao decide nada, so entende
 * o texto baguncado do OCR e preenche os campos. Quem aprova ou reprova e o
 * ValidacaoService, em PHP.
 */
class ExtracaoService
{
    private const TEMPO_LIMITE_SEGUNDOS = 120;

    /**
     * Prompt e documento somados ficam entre 900 e 1.450 tokens nos arquivos de
     * teste, sendo o fato relevante da empresa 07 o maior. 4096 da folga sem
     * pesar na memoria do container.
     */
    private const TAMANHO_CONTEXTO = 4096;

    private const CAMPOS_OBRIGATORIOS = [
        'e_comprovante_cnpj',
        'cnpj',
        'razao_social',
        'data_inscricao_cnpj',
        'situacao_cadastral',
    ];

    /**
     * @return array{dados: array<string, mixed>, bruto: array<string, mixed>, modelo: string, tempo_ms: int}
     */
    public function extrair(string $textoOcr): array
    {
        $config = ConfiguracaoAvaliacao::atual();
        $url = rtrim($config->ollama_url, '/') . '/api/chat';

        $inicio = microtime(true);

        try {
            $resposta = Http::timeout(self::TEMPO_LIMITE_SEGUNDOS)
                ->post($url, $this->requisicao($config->modelo_llm, $textoOcr));
        } catch (ConnectionException $e) {
            throw ExtracaoException::ollamaInacessivel($config->ollama_url, $e->getMessage());
        } catch (Throwable $e) {
            throw ExtracaoException::ollamaInacessivel($config->ollama_url, $e->getMessage());
        }

        $tempoMs = (int) round((microtime(true) - $inicio) * 1000);

        if ($resposta->failed()) {
            throw ExtracaoException::respostaComErro($resposta->status(), $resposta->body());
        }

        $conteudo = (string) $resposta->json('message.content', '');

        if (trim($conteudo) === '') {
            throw ExtracaoException::respostaVazia();
        }

        $bruto = $this->decodificar($this->semRaciocinio($conteudo));

        foreach (self::CAMPOS_OBRIGATORIOS as $campo) {
            if (! array_key_exists($campo, $bruto)) {
                throw ExtracaoException::campoFaltando($campo);
            }
        }

        return [
            'dados' => $this->posProcessar($bruto),
            'bruto' => $bruto,
            'modelo' => $config->modelo_llm,
            'tempo_ms' => $tempoMs,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function requisicao(string $modelo, string $textoOcr): array
    {
        return [
            'model' => $modelo,
            'stream' => false,
            'think' => false,
            'format' => $this->schema(),
            'options' => [
                'temperature' => 0,
                'num_ctx' => self::TAMANHO_CONTEXTO,
            ],
            'keep_alive' => '5m',
            'messages' => [
                ['role' => 'system', 'content' => $this->promptDeSistema()],
                ['role' => 'user', 'content' => $textoOcr],
            ],
        ];
    }

    /**
     * O schema obriga a resposta a seguir o formato. Tambem e o que faz o Qwen3
     * parar de escrever raciocinio: sem ele, o 'think: false' nao basta.
     *
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'e_comprovante_cnpj' => ['type' => 'boolean'],
                'cnpj' => ['type' => ['string', 'null']],
                'razao_social' => ['type' => ['string', 'null']],
                'data_inscricao_cnpj' => ['type' => ['string', 'null']],
                'situacao_cadastral' => ['type' => ['string', 'null']],
            ],
            'required' => self::CAMPOS_OBRIGATORIOS,
        ];
    }

    private function promptDeSistema(): string
    {
        return <<<'PROMPT'
        Voce extrai dados de documentos brasileiros. Recebe o texto de um documento obtido por OCR.
        Responda apenas com JSON.

        O texto vem de um layout de duas colunas e sai desorganizado: o valor de um campo pode
        aparecer antes do rotulo, depois dele, ou na mesma linha. Procure o valor em todo o texto.
        O OCR tambem erra acentos, entao os rotulos podem vir com pequenas variacoes de grafia.

        E comum os rotulos virem em pares, seguidos dos valores na mesma ordem. Exemplo:

            NOME EMPRESARIAL
            DATA DE CONSTITUICAO
            FULANO COMERCIO DE ALIMENTOS LTDA
            15/04/2010
            TITULO DO ESTABELECIMENTO (NOME DE FANTASIA)
            PORTE
            MERCADINHO DO FULANO

        Ali o NOME EMPRESARIAL e "FULANO COMERCIO DE ALIMENTOS LTDA", a DATA DE CONSTITUICAO e
        15/04/2010 e "MERCADINHO DO FULANO" e apenas o nome fantasia, que nao interessa.

        Campos:
        - e_comprovante_cnpj: true somente se o proprio texto trouxer o titulo "COMPROVANTE DE
          INSCRICAO E DE SITUACAO CADASTRAL" junto dos rotulos "NUMERO DE INSCRICAO" e
          "SITUACAO CADASTRAL". Se esses elementos nao aparecerem, responda false, mesmo que o
          documento cite um CNPJ, traga o nome da empresa ou pareca oficial. Contratos, notas
          fiscais, atas, estatutos, fatos relevantes e balancos citam CNPJ e nao sao
          comprovantes. Quando a resposta for false, os outros quatro campos devem ser null:
          nao copie valores de um documento que nao e comprovante.
        - cnpj: valor do campo "NUMERO DE INSCRICAO", como aparece.
        - razao_social: valor do campo "NOME EMPRESARIAL". Para encontra-lo, ache a linha que
          contem exatamente "NOME EMPRESARIAL" e pegue a primeira linha seguinte que NAO seja
          outro rotulo. Sao rotulos, entre outros: DATA DE CONSTITUICAO, PORTE, TITULO DO
          ESTABELECIMENTO (NOME DE FANTASIA), CODIGO E DESCRICAO DA ATIVIDADE ECONOMICA.
          O valor que vem depois de "TITULO DO ESTABELECIMENTO (NOME DE FANTASIA)" e o nome
          fantasia e NAO serve, mesmo que se pareca com o nome da empresa.
        - data_inscricao_cnpj: valor do campo "DATA DE INSCRICAO NO CNPJ", no formato DD/MM/AAAA.
          Nao use a "DATA DE CONSTITUICAO", que fica logo abaixo e pode ter outro valor.
          Nao use a data de emissao do comprovante ("Emitido no dia ..."), nem a data que aparece
          no topo da pagina impressa.
        - situacao_cadastral: valor do campo "SITUACAO CADASTRAL". E uma palavra so, como ATIVA,
          BAIXADA, INAPTA ou SUSPENSA. Nao confunda com "DATA DA SITUACAO CADASTRAL" nem com
          "MOTIVO DE SITUACAO CADASTRAL".

        Se um campo nao existir no texto, use null. Nao invente valores.
        PROMPT;
    }

    /**
     * O Qwen3 as vezes escreve o raciocinio antes da resposta, fechando com
     * </think>. Fica so o que vem depois.
     */
    private function semRaciocinio(string $conteudo): string
    {
        $posicao = mb_strrpos($conteudo, '</think>');

        if ($posicao === false) {
            return trim($conteudo);
        }

        return trim(mb_substr($conteudo, $posicao + mb_strlen('</think>')));
    }

    /**
     * @return array<string, mixed>
     */
    private function decodificar(string $conteudo): array
    {
        try {
            $dados = json_decode($conteudo, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ExtracaoException::jsonInvalido($conteudo);
        }

        if (! is_array($dados)) {
            throw ExtracaoException::jsonInvalido($conteudo);
        }

        return $dados;
    }

    /**
     * @param  array<string, mixed>  $bruto
     * @return array<string, mixed>
     */
    private function posProcessar(array $bruto): array
    {
        return [
            'e_comprovante_cnpj' => (bool) $bruto['e_comprovante_cnpj'],
            'cnpj' => $this->somenteDigitos($bruto['cnpj']),
            'razao_social' => $this->emMaiusculas($bruto['razao_social']),
            'data_inscricao_cnpj' => $this->paraDataIso($bruto['data_inscricao_cnpj']),
            'situacao_cadastral' => $this->emMaiusculas($bruto['situacao_cadastral']),
        ];
    }

    private function somenteDigitos(mixed $valor): ?string
    {
        if (! is_string($valor)) {
            return null;
        }

        $digitos = preg_replace('/\D/', '', $valor) ?? '';

        return $digitos === '' ? null : $digitos;
    }

    private function emMaiusculas(mixed $valor): ?string
    {
        if (! is_string($valor)) {
            return null;
        }

        $limpo = preg_replace('/\s+/u', ' ', trim($valor)) ?? '';

        return $limpo === '' ? null : mb_strtoupper($limpo);
    }

    /**
     * Converte DD/MM/AAAA para Y-m-d. Data impossivel, como 31/02, vira null.
     */
    private function paraDataIso(mixed $valor): ?string
    {
        if (! is_string($valor) || ! preg_match('#^\s*(\d{2})/(\d{2})/(\d{4})\s*$#', $valor, $partes)) {
            return null;
        }

        [, $dia, $mes, $ano] = $partes;

        if (! checkdate((int) $mes, (int) $dia, (int) $ano)) {
            return null;
        }

        return DateTimeImmutable::createFromFormat('!d/m/Y', "{$dia}/{$mes}/{$ano}")
            ->format('Y-m-d');
    }
}
