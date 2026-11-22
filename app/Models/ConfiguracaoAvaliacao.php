<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConfiguracaoAvaliacao extends Model
{
    protected $table = 'configuracoes_avaliacao';

    protected $fillable = [
        'data_fim_inscricoes',
        'anos_minimos',
        'modelo_llm',
        'ollama_url',
    ];

    protected function casts(): array
    {
        return [
            'data_fim_inscricoes' => 'date',
            'anos_minimos' => 'integer',
        ];
    }

    /**
     * Tabela de registro unico: o sistema sempre usa o primeiro registro.
     * Se ainda nao existir, cria com os padroes.
     */
    public static function atual(): self
    {
        return static::query()->orderBy('id')->first()
            ?? static::query()->create([
                'data_fim_inscricoes' => null,
                'anos_minimos' => 2,
                'modelo_llm' => 'qwen3:4b',
                'ollama_url' => 'http://ollama:11434',
            ]);
    }
}
