<?php

namespace Tests\Feature;

use App\Models\ConfiguracaoAvaliacao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A tela de configuracoes com o Ollama simulado: nenhum teste aqui depende do
 * container estar de pe, inclusive os que verificam o comportamento quando ele
 * nao esta.
 */
class ConfiguracaoTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_abre_preenchida_com_os_valores_atuais(): void
    {
        $this->comOllamaRespondendo();

        $resposta = $this->get(route('configuracoes.edit'));

        $resposta->assertOk();
        $resposta->assertSee('2026-11-30');
        $resposta->assertSee('http://ollama:11434');
        $resposta->assertSee('A regra de tempo mínimo de inscrição no CNPJ é calculada até esta data.');
    }

    public function test_lista_os_modelos_instalados_no_ollama(): void
    {
        $this->comOllamaRespondendo(['qwen3:4b', 'gemma3:1b']);

        $resposta = $this->get(route('configuracoes.edit'));

        $resposta->assertSee('<select', escape: false);
        $resposta->assertSee('gemma3:1b');
        $resposta->assertDontSee('Ollama não respondeu');
    }

    public function test_salvar_altera_o_registro_unico(): void
    {
        $this->comOllamaRespondendo(['qwen3:4b', 'gemma3:1b']);

        $resposta = $this->put(route('configuracoes.update'), [
            'data_fim_inscricoes' => '2027-01-31',
            'anos_minimos' => 3,
            'modelo_llm' => 'gemma3:1b',
            'ollama_url' => 'http://ollama:11434',
        ]);

        $resposta->assertRedirect(route('configuracoes.edit'));
        $resposta->assertSessionHas('sucesso');

        $this->assertSame(1, ConfiguracaoAvaliacao::query()->count());

        $config = ConfiguracaoAvaliacao::atual();
        $this->assertSame('2027-01-31', $config->data_fim_inscricoes->format('Y-m-d'));
        $this->assertSame(3, $config->anos_minimos);
        $this->assertSame('gemma3:1b', $config->modelo_llm);
    }

    /**
     * A URL do Ollama e editavel pela tela. Se ela apontar para outro servico
     * que responda 200 com outro JSON, a tela tem que cair no aviso, nao em
     * erro 500.
     */
    public function test_abre_com_aviso_quando_a_url_responde_outra_coisa(): void
    {
        Http::fake([
            // 200, com a chave 'models' presente, mas trazendo strings no lugar
            // dos objetos que o Ollama devolve. E a forma que estourava a tela.
            '*/api/tags' => Http::response(['models' => ['qwen3:4b', 'gemma3:1b']]),
            '*/api/chat' => Http::response(['erro' => 'nao sou o ollama'], 404),
        ]);

        $resposta = $this->get(route('configuracoes.edit'));

        $resposta->assertOk();
        $resposta->assertSee('Ollama não respondeu');
    }

    public function test_abre_com_aviso_quando_o_ollama_nao_responde(): void
    {
        $this->comOllamaForaDoAr();

        $resposta = $this->get(route('configuracoes.edit'));

        $resposta->assertOk();
        $resposta->assertSee('Ollama não respondeu');
        $resposta->assertDontSee('<select', escape: false);
    }

    /**
     * Com o Ollama parado nao ha lista para validar contra, entao o modelo
     * digitado tem que passar — senao a tela ficaria insalvavel.
     */
    public function test_aceita_modelo_digitado_quando_o_ollama_nao_responde(): void
    {
        $this->comOllamaForaDoAr();

        $resposta = $this->put(route('configuracoes.update'), [
            'data_fim_inscricoes' => '2026-11-30',
            'anos_minimos' => 2,
            'modelo_llm' => 'modelo-digitado-a-mao',
            'ollama_url' => 'http://ollama:11434',
        ]);

        $resposta->assertSessionHasNoErrors();
        $this->assertSame('modelo-digitado-a-mao', ConfiguracaoAvaliacao::atual()->modelo_llm);
    }

    public function test_recusa_modelo_fora_da_lista_quando_o_ollama_responde(): void
    {
        $this->comOllamaRespondendo(['qwen3:4b']);

        $resposta = $this->put(route('configuracoes.update'), [
            'data_fim_inscricoes' => '2026-11-30',
            'anos_minimos' => 2,
            'modelo_llm' => 'modelo-que-nao-existe',
            'ollama_url' => 'http://ollama:11434',
        ]);

        $resposta->assertSessionHasErrors('modelo_llm');
    }

    public function test_recusa_valores_invalidos(): void
    {
        $this->comOllamaRespondendo();

        $resposta = $this->put(route('configuracoes.update'), [
            'data_fim_inscricoes' => '',
            'anos_minimos' => 11,
            'modelo_llm' => 'qwen3:4b',
            'ollama_url' => 'nao-e-url',
        ]);

        $resposta->assertSessionHasErrors(['data_fim_inscricoes', 'anos_minimos', 'ollama_url']);
        // Nada salvo.
        $this->assertSame(2, ConfiguracaoAvaliacao::atual()->anos_minimos);
    }

    public function test_testar_conexao_informa_sucesso_com_o_tempo(): void
    {
        $this->comOllamaRespondendo();

        $resposta = $this->from(route('configuracoes.edit'))
            ->post(route('configuracoes.testar'), ['modelo_llm' => 'qwen3:4b']);

        $resposta->assertRedirect(route('configuracoes.edit'));
        $this->assertStringContainsString('OK (', session('sucesso'));
    }

    public function test_testar_conexao_informa_o_erro_com_o_ollama_parado(): void
    {
        $this->comOllamaForaDoAr();

        $resposta = $this->from(route('configuracoes.edit'))
            ->post(route('configuracoes.testar'), ['modelo_llm' => 'qwen3:4b']);

        $resposta->assertRedirect(route('configuracoes.edit'));
        $this->assertStringContainsString('não respondeu', session('erro'));
    }

    /**
     * @param  string[]  $modelos
     */
    private function comOllamaRespondendo(array $modelos = ['qwen3:4b']): void
    {
        Http::fake([
            '*/api/tags' => Http::response([
                'models' => array_map(fn (string $nome): array => ['name' => $nome], $modelos),
            ]),
            '*/api/chat' => Http::response(['message' => ['content' => 'ok']]),
        ]);
    }

    private function comOllamaForaDoAr(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection refused'));
    }
}
