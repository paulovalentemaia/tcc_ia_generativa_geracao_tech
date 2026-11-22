<?php

namespace Tests\Unit;

use App\Models\ConfiguracaoAvaliacao;
use App\Models\Empresa;
use App\Services\ValidacaoService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Testa as seis regras da spec 05 com dados fixos.
 *
 * Nada aqui toca o banco, o OCR ou o Ollama: os models sao instanciados em
 * memoria e os campos extraidos entram como array, no mesmo formato que o
 * ExtracaoService devolve em 'dados' (CNPJ so com digitos, data em Y-m-d,
 * razao social e situacao em maiusculas).
 */
class ValidacaoServiceTest extends TestCase
{
    private ValidacaoService $validacao;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validacao = new ValidacaoService;
    }

    public function test_aprova_quando_tudo_confere(): void
    {
        $resultado = $this->validacao->validar($this->empresa(), $this->dados(), $this->config());

        $this->assertSame('aprovado', $resultado['resultado']);
        $this->assertSame(['Todas as verificações foram aprovadas.'], $resultado['observacoes']);
    }

    public function test_regra_1_reprova_quando_nao_e_comprovante(): void
    {
        $resultado = $this->validacao->validar(
            $this->empresa(),
            $this->dados(['e_comprovante_cnpj' => false]),
            $this->config(),
        );

        $this->assertSame('reprovado', $resultado['resultado']);
        $this->assertSame(
            ['O arquivo enviado não é um Comprovante de Inscrição de CNPJ.'],
            $resultado['observacoes'],
        );
    }

    /**
     * O qwen3 as vezes preenche CNPJ e razao social de um documento que nao e
     * comprovante (foi o que aconteceu com o Fato Relevante da empresa 07).
     * A regra 1 reprova na hora e os outros campos nem sao conferidos.
     */
    public function test_regra_1_ignora_os_demais_campos(): void
    {
        $resultado = $this->validacao->validar(
            $this->empresa(),
            [
                'e_comprovante_cnpj' => false,
                'cnpj' => '99999999999999',
                'razao_social' => 'OUTRA EMPRESA QUALQUER',
                'data_inscricao_cnpj' => null,
                'situacao_cadastral' => null,
            ],
            $this->config(),
        );

        $this->assertCount(1, $resultado['observacoes']);
    }

    public function test_regra_2_reprova_cnpj_divergente(): void
    {
        $resultado = $this->validacao->validar(
            $this->empresa(),
            $this->dados(['cnpj' => '07526557000100']),
            $this->config(),
        );

        $this->assertSame('reprovado', $resultado['resultado']);
        $this->assertSame(
            ['CNPJ do comprovante (07.526.557/0001-00) diferente do cadastrado (07.526.557/0002-90).'],
            $resultado['observacoes'],
        );
    }

    public function test_regra_3_reprova_razao_social_divergente(): void
    {
        $resultado = $this->validacao->validar(
            $this->empresa(),
            $this->dados(['razao_social' => 'PADARIA DO JOAO LTDA']),
            $this->config(),
        );

        $this->assertSame('reprovado', $resultado['resultado']);
        $this->assertStringContainsString('Razão social do comprovante', $resultado['observacoes'][0]);
    }

    /**
     * @param  string  $extraida  variacoes que o OCR produz na pratica
     */
    #[DataProvider('razoesSociaisEquivalentes')]
    public function test_regra_3_tolera_erros_de_ocr(string $extraida): void
    {
        $resultado = $this->validacao->validar(
            $this->empresa(['razao_social' => 'AMBEV S.A.']),
            $this->dados(['razao_social' => $extraida]),
            $this->config(),
        );

        $this->assertSame('aprovado', $resultado['resultado'], "Deveria aceitar '{$extraida}'");
    }

    /**
     * @return array<string, array{string}>
     */
    public static function razoesSociaisEquivalentes(): array
    {
        return [
            'sem pontuacao' => ['AMBEV SA'],
            'com barra' => ['AMBEV S/A'],
            'espaco a mais' => ['AMBEV  S.A.'],
            'minusculas' => ['Ambev S.A.'],
        ];
    }

    public function test_regra_4_reprova_data_divergente(): void
    {
        $resultado = $this->validacao->validar(
            $this->empresa(),
            $this->dados(['data_inscricao_cnpj' => '1966-10-24']),
            $this->config(),
        );

        $this->assertSame('reprovado', $resultado['resultado']);
        $this->assertContains(
            'Data de inscrição no CNPJ do comprovante (24/10/1966) diferente da informada (19/07/2005).',
            $resultado['observacoes'],
        );
    }

    /**
     * Os tres casos exigidos pela spec 05. A data cadastrada acompanha a extraida
     * para isolar a regra 5 da regra 4.
     */
    public function test_regra_5_reprova_inscricao_recente(): void
    {
        $resultado = $this->validar('2025-03-10', anosMinimos: 2);

        $this->assertSame('reprovado', $resultado['resultado']);
        $this->assertContains(
            'Empresa com menos de 2 anos de inscrição em 30/11/2026 (fim das inscrições).',
            $resultado['observacoes'],
        );
    }

    public function test_regra_5_aprova_inscricao_antiga(): void
    {
        $this->assertSame('aprovado', $this->validar('2024-03-10', anosMinimos: 2)['resultado']);
    }

    public function test_regra_5_segue_a_configuracao_de_anos_minimos(): void
    {
        $this->assertSame('aprovado', $this->validar('2025-03-10', anosMinimos: 1)['resultado']);
    }

    /**
     * Esses textos aparecem numa tabela na tela de detalhe, entao "1 anos"
     * chamaria atencao pelo motivo errado.
     */
    public function test_regra_5_escreve_um_ano_no_singular(): void
    {
        $resultado = $this->validar('2026-06-10', anosMinimos: 1);

        $regra5 = collect($resultado['verificacoes'])->firstWhere('regra', 5);

        $this->assertSame('1 ano até 30/11/2026', $regra5['cadastrado']);
        $this->assertStringContainsString('menos de 1 ano de inscrição', $regra5['observacao']);
    }

    public function test_regra_6_reprova_situacao_diferente_de_ativa(): void
    {
        $resultado = $this->validacao->validar(
            $this->empresa(),
            $this->dados(['situacao_cadastral' => 'BAIXADA']),
            $this->config(),
        );

        $this->assertSame('reprovado', $resultado['resultado']);
        $this->assertSame(
            ['Situação cadastral BAIXADA (deveria ser ATIVA).'],
            $resultado['observacoes'],
        );
    }

    public function test_campo_nulo_reprova_com_mensagem_de_campo_ausente(): void
    {
        $resultado = $this->validacao->validar(
            $this->empresa(),
            $this->dados(['situacao_cadastral' => null]),
            $this->config(),
        );

        $this->assertSame('reprovado', $resultado['resultado']);
        $this->assertSame(
            ['Campo Situação cadastral não encontrado no comprovante.'],
            $resultado['observacoes'],
        );
    }

    /**
     * Sem a data nao da para conferir a regra 4 nem calcular a regra 5, entao sai
     * uma observacao so, nao duas.
     */
    public function test_data_nula_gera_uma_observacao_so(): void
    {
        $resultado = $this->validacao->validar(
            $this->empresa(),
            $this->dados(['data_inscricao_cnpj' => null]),
            $this->config(),
        );

        $this->assertSame(
            ['Campo Data de inscrição no CNPJ não encontrado no comprovante.'],
            $resultado['observacoes'],
        );
    }

    public function test_acumula_as_observacoes_das_regras_2_a_6(): void
    {
        $resultado = $this->validacao->validar(
            $this->empresa(),
            $this->dados([
                'cnpj' => '07526557000100',
                'razao_social' => 'PADARIA DO JOAO LTDA',
                'data_inscricao_cnpj' => '2025-03-10',
                'situacao_cadastral' => 'INAPTA',
            ]),
            $this->config(),
        );

        $this->assertSame('reprovado', $resultado['resultado']);
        // Regras 2, 3, 4, 5 e 6.
        $this->assertCount(5, $resultado['observacoes']);
    }

    /**
     * A tela de detalhe (spec 07) monta a tabela das seis regras a partir disto,
     * entao as seis saem sempre, na ordem, mesmo quando nao foram avaliadas.
     */
    public function test_devolve_as_seis_verificacoes_na_ordem(): void
    {
        $resultado = $this->validacao->validar($this->empresa(), $this->dados(), $this->config());

        $this->assertCount(6, $resultado['verificacoes']);
        $this->assertSame([1, 2, 3, 4, 5, 6], array_column($resultado['verificacoes'], 'regra'));
        $this->assertSame(
            [true, true, true, true, true, true],
            array_column($resultado['verificacoes'], 'ok'),
        );
    }

    public function test_verificacao_traz_o_cadastrado_e_o_do_comprovante(): void
    {
        $resultado = $this->validacao->validar(
            $this->empresa(),
            $this->dados(['situacao_cadastral' => 'BAIXADA']),
            $this->config(),
        );

        $situacao = collect($resultado['verificacoes'])->firstWhere('regra', 6);

        $this->assertSame('Situação cadastral', $situacao['rotulo']);
        $this->assertSame('ATIVA', $situacao['cadastrado']);
        $this->assertSame('BAIXADA', $situacao['comprovante']);
        $this->assertFalse($situacao['ok']);
        $this->assertSame('Situação cadastral BAIXADA (deveria ser ATIVA).', $situacao['observacao']);
    }

    /**
     * Quando a regra 1 reprova, as outras cinco nao sao avaliadas: ficam com
     * 'ok' nulo, que a tela mostra como traco, em vez de virarem falsos erros.
     */
    public function test_regra_1_deixa_as_demais_como_nao_verificadas(): void
    {
        $resultado = $this->validacao->validar(
            $this->empresa(),
            $this->dados(['e_comprovante_cnpj' => false]),
            $this->config(),
        );

        $this->assertCount(6, $resultado['verificacoes']);
        $this->assertSame(
            [false, null, null, null, null, null],
            array_column($resultado['verificacoes'], 'ok'),
        );
    }

    /**
     * @return array{resultado: string, observacoes: string[], verificacoes: array<int, array<string, mixed>>}
     */
    private function validar(string $dataInscricao, int $anosMinimos): array
    {
        return $this->validacao->validar(
            $this->empresa(['data_inscricao_cnpj' => $dataInscricao]),
            $this->dados(['data_inscricao_cnpj' => $dataInscricao]),
            $this->config(['anos_minimos' => $anosMinimos]),
        );
    }

    /**
     * @param  array<string, mixed>  $sobrescrever
     */
    private function empresa(array $sobrescrever = []): Empresa
    {
        return new Empresa(array_merge([
            'razao_social' => 'AMBEV S.A.',
            'cnpj' => '07526557000290',
            'data_inscricao_cnpj' => '2005-07-19',
            'arquivo_comprovante' => 'comprovantes/empresa_04.pdf',
            'situacao' => 'submetido',
        ], $sobrescrever));
    }

    /**
     * Campos extraidos de um comprovante que confere com a empresa acima.
     *
     * @param  array<string, mixed>  $sobrescrever
     * @return array<string, mixed>
     */
    private function dados(array $sobrescrever = []): array
    {
        return array_merge([
            'e_comprovante_cnpj' => true,
            'cnpj' => '07526557000290',
            'razao_social' => 'AMBEV S.A.',
            'data_inscricao_cnpj' => '2005-07-19',
            'situacao_cadastral' => 'ATIVA',
        ], $sobrescrever);
    }

    /**
     * @param  array<string, mixed>  $sobrescrever
     */
    private function config(array $sobrescrever = []): ConfiguracaoAvaliacao
    {
        return new ConfiguracaoAvaliacao(array_merge([
            'data_fim_inscricoes' => '2026-11-30',
            'anos_minimos' => 2,
            'modelo_llm' => 'qwen3:4b',
            'ollama_url' => 'http://ollama:11434',
        ], $sobrescrever));
    }
}
