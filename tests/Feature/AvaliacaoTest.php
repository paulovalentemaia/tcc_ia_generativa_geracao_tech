<?php

namespace Tests\Feature;

use App\Models\Avaliacao;
use App\Models\ConfiguracaoAvaliacao;
use App\Models\Empresa;
use App\Services\OcrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A tela de avaliacoes com OCR e LLM simulados.
 *
 * O OcrService e substituido no container porque ele roda um processo Python de
 * verdade; o Ollama vai de Http::fake(). Assim o teste exercita o fluxo todo —
 * controller, orquestrador, regras e gravacao — em milissegundos e sem depender
 * de container nenhum.
 */
class AvaliacaoTest extends TestCase
{
    use RefreshDatabase;

    private const TEXTO_OCR = "COMPROVANTE DE INSCRICAO E DE SITUACAO CADASTRAL\nNUMERO DE INSCRICAO";

    protected function setUp(): void
    {
        parent::setUp();

        ConfiguracaoAvaliacao::query()->create([
            'data_fim_inscricoes' => '2026-11-30',
            'anos_minimos' => 2,
            'modelo_llm' => 'qwen3:4b',
            'ollama_url' => 'http://ollama:11434',
        ]);
    }

    public function test_a_lista_mostra_so_as_empresas_submetidas(): void
    {
        $submetida = $this->empresa();
        $criando = $this->empresa(['razao_social' => 'VALE S.A.', 'cnpj' => '33592510000154', 'situacao' => 'criando']);

        $resposta = $this->get(route('avaliacoes.index'));

        $resposta->assertOk();
        $resposta->assertSee($submetida->razao_social);
        $resposta->assertDontSee($criando->razao_social);
    }

    public function test_a_lista_mostra_a_configuracao_em_uso(): void
    {
        $this->empresa();

        $resposta = $this->get(route('avaliacoes.index'));

        $resposta->assertSee('30/11/2026');
        $resposta->assertSee('2 anos');
        $resposta->assertSee('qwen3:4b');
    }

    public function test_empresa_nunca_avaliada_nao_tem_resultado_nem_detalhe(): void
    {
        $this->empresa();

        $resposta = $this->get(route('avaliacoes.index'));

        $resposta->assertDontSee('Ver detalhe');
        $resposta->assertSee('1 empresas submetidas', escape: false);
        $resposta->assertSee('1 nunca avaliadas', escape: false);
    }

    public function test_avaliar_cria_a_avaliacao_e_volta_com_mensagem(): void
    {
        $empresa = $this->empresa();
        $this->comOcrEExtracaoFuncionando();

        $resposta = $this->post(route('avaliacoes.avaliar', $empresa));

        $resposta->assertRedirect(route('avaliacoes.index'));
        $resposta->assertSessionHas('sucesso');

        $this->assertSame(1, Avaliacao::query()->count());
        $this->assertSame('aprovado', Avaliacao::query()->sole()->resultado);
    }

    public function test_avaliar_todas_avalia_cada_empresa_submetida(): void
    {
        $this->empresa();
        $this->empresa(['razao_social' => 'OUTRA EMPRESA LTDA', 'cnpj' => '11222333000181']);
        $this->empresa(['razao_social' => 'NAO SUBMETIDA LTDA', 'cnpj' => '11222333000182', 'situacao' => 'criando']);

        $this->comOcrEExtracaoFuncionando();

        $resposta = $this->post(route('avaliacoes.todas'));

        $resposta->assertRedirect(route('avaliacoes.index'));
        $this->assertSame(2, Avaliacao::query()->count());
    }

    public function test_o_detalhe_mostra_as_cinco_secoes(): void
    {
        $empresa = $this->empresa();
        $this->comOcrEExtracaoFuncionando();
        $this->post(route('avaliacoes.avaliar', $empresa));

        $resposta = $this->get(route('avaliacoes.detalhe', Avaliacao::query()->sole()));

        $resposta->assertOk();
        $resposta->assertSee('Documento');
        $resposta->assertSee('Texto lido pelo OCR');
        $resposta->assertSee('Dados extraídos pelo LLM');
        $resposta->assertSee('Verificações');
        $resposta->assertSee('Resultado');
        // Conteudo real das secoes, nao so os titulos.
        $resposta->assertSee('COMPROVANTE DE INSCRICAO E DE SITUACAO CADASTRAL');
        $resposta->assertSee('qwen3:4b');
        $resposta->assertSee('Todas as verificações foram aprovadas.');
    }

    public function test_o_detalhe_mostra_as_seis_regras_com_os_dois_valores(): void
    {
        $empresa = $this->empresa();
        $this->comOcrEExtracaoFuncionando(['situacao_cadastral' => 'BAIXADA']);
        $this->post(route('avaliacoes.avaliar', $empresa));

        $resposta = $this->get(route('avaliacoes.detalhe', Avaliacao::query()->sole()));

        $resposta->assertSee('Situação cadastral');
        $resposta->assertSee('BAIXADA');
        $resposta->assertSee('Tempo mínimo de inscrição');
        $resposta->assertSee('2 anos até 30/11/2026');
    }

    public function test_avaliacao_com_erro_mostra_a_mensagem_no_detalhe(): void
    {
        $empresa = $this->empresa();
        $this->comOcrFuncionando();
        Http::fake(fn () => Http::response('sem servico', 500));

        $this->post(route('avaliacoes.avaliar', $empresa));

        $avaliacao = Avaliacao::query()->sole();
        $this->assertSame('erro', $avaliacao->resultado);

        $resposta = $this->get(route('avaliacoes.detalhe', $avaliacao));

        $resposta->assertSee('erro');
        $resposta->assertSee('O Ollama respondeu HTTP 500', escape: false);
        // Sem verificacoes, mas o texto do OCR ja obtido continua la.
        $resposta->assertSee('não há verificações');
        $resposta->assertSee('COMPROVANTE DE INSCRICAO E DE SITUACAO CADASTRAL');
    }

    public function test_o_comprovante_e_servido_pelo_laravel(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('comprovantes/empresa_01.pdf', '%PDF-falso');

        $empresa = $this->empresa();

        $resposta = $this->get(route('avaliacoes.comprovante', $empresa));

        $resposta->assertOk();
        $this->assertSame('%PDF-falso', $resposta->streamedContent());
    }

    public function test_comprovante_ausente_devolve_404(): void
    {
        Storage::fake('local');

        $this->get(route('avaliacoes.comprovante', $this->empresa()))->assertNotFound();
    }

    public function test_bloqueia_quando_falta_a_data_de_fim_das_inscricoes(): void
    {
        ConfiguracaoAvaliacao::atual()->update(['data_fim_inscricoes' => null]);
        $empresa = $this->empresa();

        $resposta = $this->post(route('avaliacoes.avaliar', $empresa));

        $resposta->assertRedirect(route('avaliacoes.index'));
        $resposta->assertSessionHas('erro');
        $this->assertSame(0, Avaliacao::query()->count());
    }

    /**
     * @param  array<string, mixed>  $sobrescrever
     */
    private function empresa(array $sobrescrever = []): Empresa
    {
        return Empresa::query()->create(array_merge([
            'razao_social' => 'PETROLEO BRASILEIRO S A PETROBRAS',
            'cnpj' => '33000167000101',
            'data_inscricao_cnpj' => '1966-09-28',
            'arquivo_comprovante' => 'comprovantes/empresa_01.pdf',
            'situacao' => 'submetido',
        ], $sobrescrever));
    }

    private function comOcrFuncionando(): void
    {
        $ocr = $this->createMock(OcrService::class);
        $ocr->method('extrairTexto')->willReturn(self::TEXTO_OCR);

        $this->instance(OcrService::class, $ocr);
    }

    /**
     * @param  array<string, mixed>  $sobrescrever  campos que o LLM devolve
     */
    private function comOcrEExtracaoFuncionando(array $sobrescrever = []): void
    {
        $this->comOcrFuncionando();

        $campos = array_merge([
            'e_comprovante_cnpj' => true,
            'cnpj' => '33.000.167/0001-01',
            'razao_social' => 'PETROLEO BRASILEIRO S A PETROBRAS',
            'data_inscricao_cnpj' => '28/09/1966',
            'situacao_cadastral' => 'ATIVA',
        ], $sobrescrever);

        Http::fake([
            '*/api/chat' => Http::response([
                'message' => ['content' => json_encode($campos)],
            ]),
        ]);
    }
}
