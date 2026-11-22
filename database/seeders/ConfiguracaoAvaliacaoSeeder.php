<?php

namespace Database\Seeders;

use App\Models\ConfiguracaoAvaliacao;
use Illuminate\Database\Seeder;

class ConfiguracaoAvaliacaoSeeder extends Seeder
{
    public function run(): void
    {
        ConfiguracaoAvaliacao::query()->delete();

        ConfiguracaoAvaliacao::query()->create([
            'data_fim_inscricoes' => '2026-11-30',
            'anos_minimos' => 2,
            'modelo_llm' => 'qwen3:4b',
            'ollama_url' => 'http://ollama:11434',
        ]);
    }
}
