<?php

namespace App\Services;

use App\Models\ConfiguracaoAvaliacao;
use App\Models\Empresa;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Aplica as regras de negocio sobre os campos extraidos do comprovante.
 *
 * Aqui nao ha IA nenhuma: e PHP deterministico, com as seis regras da spec 05 na
 * ordem. Essa separacao e proposital — o LLM so le o documento, e quem aprova ou
 * reprova e este service, que pode ser testado sem OCR e sem Ollama.
 *
 * Cada regra devolve uma verificacao: o que estava cadastrado, o que o
 * comprovante diz e se confere. As observacoes em texto saem dessas
 * verificacoes, e nao o contrario, para que a tela de detalhe e a mensagem
 * mostrada ao usuario nunca contem historias diferentes.
 */
class ValidacaoService
{
    /**
     * Abaixo disso a razao social e considerada diferente. Os 90% toleram os
     * erros pequenos do OCR (acento trocado, uma letra a mais) sem deixar passar
     * uma empresa que e realmente outra.
     */
    private const SIMILARIDADE_MINIMA = 90.0;

    private const SITUACAO_ESPERADA = 'ATIVA';

    /**
     * Estes rótulos e as mensagens abaixo vão para a tela e para o banco, então
     * são escritos em português correto, com acento. Os comentários do código
     * seguem sem acento, como no resto do projeto.
     */
    private const ROTULOS = [
        1 => 'É comprovante de CNPJ',
        2 => 'CNPJ',
        3 => 'Razão social',
        4 => 'Data de inscrição no CNPJ',
        5 => 'Tempo mínimo de inscrição',
        6 => 'Situação cadastral',
    ];

    /**
     * @param  array<string, mixed>  $dados  campos pos-processados do ExtracaoService
     * @return array{resultado: string, observacoes: string[], verificacoes: array<int, array<string, mixed>>}
     */
    public function validar(Empresa $empresa, array $dados, ConfiguracaoAvaliacao $config): array
    {
        $tipo = $this->conferirTipo($dados);

        // Regra 1: se nao e comprovante de CNPJ, nada mais interessa. O modelo as
        // vezes ainda preenche CNPJ e razao social de um documento qualquer, e
        // conferi-los daria observacoes sem sentido.
        if ($tipo['ok'] !== true) {
            return $this->montar([$tipo, ...$this->naoVerificadas($empresa, $config)]);
        }

        return $this->montar([
            $tipo,
            $this->conferirCnpj($empresa, $dados),
            $this->conferirRazaoSocial($empresa, $dados),
            ...$this->conferirDatas($empresa, $dados, $config),
            $this->conferirSituacao($dados),
        ]);
    }

    /**
     * Regra 1 — o documento e mesmo um comprovante.
     *
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     */
    private function conferirTipo(array $dados): array
    {
        $eComprovante = ($dados['e_comprovante_cnpj'] ?? false) === true;

        return $this->verificacao(
            regra: 1,
            cadastrado: 'Comprovante de Inscrição de CNPJ',
            comprovante: $eComprovante ? 'Comprovante de Inscrição de CNPJ' : 'Outro tipo de documento',
            ok: $eComprovante,
            observacao: 'O arquivo enviado não é um Comprovante de Inscrição de CNPJ.',
        );
    }

    /**
     * Regra 2 — CNPJ extraido igual ao cadastrado.
     *
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     */
    private function conferirCnpj(Empresa $empresa, array $dados): array
    {
        $extraido = $dados['cnpj'] ?? null;

        if (! is_string($extraido) || $extraido === '') {
            return $this->campoAusente(2, $empresa->cnpjFormatado());
        }

        return $this->verificacao(
            regra: 2,
            cadastrado: $empresa->cnpjFormatado(),
            comprovante: $this->formatarCnpj($extraido),
            ok: $extraido === $empresa->cnpj,
            observacao: sprintf(
                'CNPJ do comprovante (%s) diferente do cadastrado (%s).',
                $this->formatarCnpj($extraido),
                $empresa->cnpjFormatado(),
            ),
        );
    }

    /**
     * Regra 3 — razao social confere, tolerando erros de OCR.
     *
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     */
    private function conferirRazaoSocial(Empresa $empresa, array $dados): array
    {
        $extraida = $dados['razao_social'] ?? null;

        if (! is_string($extraida) || trim($extraida) === '') {
            return $this->campoAusente(3, $empresa->razao_social);
        }

        return $this->verificacao(
            regra: 3,
            cadastrado: $empresa->razao_social,
            comprovante: $extraida,
            ok: $this->razoesSociaisConferem($extraida, $empresa->razao_social),
            observacao: sprintf(
                'Razão social do comprovante (%s) diferente da cadastrada (%s).',
                $extraida,
                $empresa->razao_social,
            ),
        );
    }

    /**
     * Regras 4 e 5 — as duas dependem da data extraida, por isso saem juntas:
     * sem data, a regra 4 aponta a falta e a 5 fica sem o que calcular.
     *
     * @param  array<string, mixed>  $dados
     * @return array<int, array<string, mixed>>
     */
    private function conferirDatas(Empresa $empresa, array $dados, ConfiguracaoAvaliacao $config): array
    {
        $cadastrada = $empresa->data_inscricao_cnpj->format('d/m/Y');
        $fim = CarbonImmutable::parse($config->data_fim_inscricoes);
        $exigido = sprintf(
            '%s até %s',
            $this->emAnos($config->anos_minimos),
            $fim->format('d/m/Y'),
        );

        $extraida = $this->comoData($dados['data_inscricao_cnpj'] ?? null);

        if ($extraida === null) {
            return [
                $this->campoAusente(4, $cadastrada),
                $this->naoVerificada(5, $exigido),
            ];
        }

        return [
            $this->verificacao(
                regra: 4,
                cadastrado: $cadastrada,
                comprovante: $extraida->format('d/m/Y'),
                ok: $extraida->isSameDay($empresa->data_inscricao_cnpj),
                observacao: sprintf(
                    'Data de inscrição no CNPJ do comprovante (%s) diferente da informada (%s).',
                    $extraida->format('d/m/Y'),
                    $cadastrada,
                ),
            ),
            $this->verificacao(
                regra: 5,
                cadastrado: $exigido,
                comprovante: sprintf(
                    '%s em %s',
                    $this->emAnos((int) $extraida->diffInYears($fim)),
                    $fim->format('d/m/Y'),
                ),
                ok: ! $extraida->addYears($config->anos_minimos)->greaterThan($fim),
                observacao: sprintf(
                    'Empresa com menos de %s de inscrição em %s (fim das inscrições).',
                    $this->emAnos($config->anos_minimos),
                    $fim->format('d/m/Y'),
                ),
            ),
        ];
    }

    /**
     * Regra 6 — situacao cadastral ATIVA.
     *
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     */
    private function conferirSituacao(array $dados): array
    {
        $situacao = $dados['situacao_cadastral'] ?? null;

        if (! is_string($situacao) || trim($situacao) === '') {
            return $this->campoAusente(6, self::SITUACAO_ESPERADA);
        }

        $situacao = mb_strtoupper(trim($situacao));

        return $this->verificacao(
            regra: 6,
            cadastrado: self::SITUACAO_ESPERADA,
            comprovante: $situacao,
            ok: $situacao === self::SITUACAO_ESPERADA,
            observacao: sprintf(
                'Situação cadastral %s (deveria ser %s).',
                $situacao,
                self::SITUACAO_ESPERADA,
            ),
        );
    }

    /**
     * Junta tudo: o resultado sai das verificacoes, e as observacoes tambem.
     *
     * @param  array<int, array<string, mixed>>  $verificacoes
     * @return array{resultado: string, observacoes: string[], verificacoes: array<int, array<string, mixed>>}
     */
    private function montar(array $verificacoes): array
    {
        $falhas = array_values(array_filter(
            $verificacoes,
            fn (array $verificacao): bool => $verificacao['ok'] === false,
        ));

        if ($falhas === []) {
            return [
                'resultado' => 'aprovado',
                'observacoes' => ['Todas as verificações foram aprovadas.'],
                'verificacoes' => $verificacoes,
            ];
        }

        return [
            'resultado' => 'reprovado',
            'observacoes' => array_map(
                fn (array $verificacao): string => $verificacao['observacao'],
                $falhas,
            ),
            'verificacoes' => $verificacoes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function verificacao(
        int $regra,
        string $cadastrado,
        string $comprovante,
        ?bool $ok,
        ?string $observacao,
    ): array {
        return [
            'regra' => $regra,
            'rotulo' => self::ROTULOS[$regra],
            'cadastrado' => $cadastrado,
            'comprovante' => $comprovante,
            'ok' => $ok,
            'observacao' => $ok === false ? $observacao : null,
        ];
    }

    /**
     * Campo que o modelo nao achou no documento: a regra falha, mas por ausencia.
     *
     * @return array<string, mixed>
     */
    private function campoAusente(int $regra, string $cadastrado): array
    {
        return $this->verificacao(
            regra: $regra,
            cadastrado: $cadastrado,
            comprovante: 'não encontrado',
            ok: false,
            observacao: sprintf('Campo %s não encontrado no comprovante.', self::ROTULOS[$regra]),
        );
    }

    /**
     * Regra que nao chegou a ser avaliada. Nao e acerto nem erro: o 'ok' fica
     * nulo e a tela mostra um traco.
     *
     * @return array<string, mixed>
     */
    private function naoVerificada(int $regra, string $cadastrado): array
    {
        return $this->verificacao(
            regra: $regra,
            cadastrado: $cadastrado,
            comprovante: 'não verificado',
            ok: null,
            observacao: null,
        );
    }

    /**
     * As regras 2 a 6 quando a regra 1 ja reprovou o documento.
     *
     * @return array<int, array<string, mixed>>
     */
    private function naoVerificadas(Empresa $empresa, ConfiguracaoAvaliacao $config): array
    {
        $fim = CarbonImmutable::parse($config->data_fim_inscricoes);

        return [
            $this->naoVerificada(2, $empresa->cnpjFormatado()),
            $this->naoVerificada(3, $empresa->razao_social),
            $this->naoVerificada(4, $empresa->data_inscricao_cnpj->format('d/m/Y')),
            $this->naoVerificada(5, sprintf(
                '%s até %s',
                $this->emAnos($config->anos_minimos),
                $fim->format('d/m/Y'),
            )),
            $this->naoVerificada(6, self::SITUACAO_ESPERADA),
        ];
    }

    private function razoesSociaisConferem(string $extraida, string $cadastrada): bool
    {
        $a = $this->normalizar($extraida);
        $b = $this->normalizar($cadastrada);

        if ($a === $b) {
            return true;
        }

        if ($a === '' || $b === '') {
            return false;
        }

        similar_text($a, $b, $percentual);

        return $percentual >= self::SIMILARIDADE_MINIMA;
    }

    /**
     * Maiusculas, sem acentos, sem pontuacao, espacos colapsados. Isso neutraliza
     * tanto a troca de acento do OCR quanto as variacoes de 'S/A', 'S.A.' e 'SA'.
     */
    private function normalizar(string $valor): string
    {
        $semAcentos = Str::ascii($valor);
        $semPontuacao = preg_replace('/[^A-Za-z0-9 ]+/', ' ', $semAcentos) ?? '';
        $colapsado = preg_replace('/\s+/', ' ', $semPontuacao) ?? '';

        return mb_strtoupper(trim($colapsado));
    }

    private function comoData(mixed $valor): ?CarbonImmutable
    {
        if (! is_string($valor) || trim($valor) === '') {
            return null;
        }

        $data = CarbonImmutable::createFromFormat('!Y-m-d', trim($valor));

        return $data === false ? null : $data;
    }

    /**
     * "1 ano", "2 anos". A tela de detalhe mostra esses textos numa tabela, e
     * "1 anos" chama atencao pelo motivo errado.
     */
    private function emAnos(int $anos): string
    {
        return $anos === 1 ? '1 ano' : "{$anos} anos";
    }

    private function formatarCnpj(string $digitos): string
    {
        return preg_replace(
            '/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/',
            '$1.$2.$3/$4-$5',
            $digitos
        ) ?? $digitos;
    }
}
