<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Avaliacao extends Model
{
    protected $table = 'avaliacoes';

    protected $fillable = [
        'empresa_id',
        'resultado',
        'observacoes',
        'texto_ocr',
        'dados_extraidos',
        'verificacoes',
        'modelo_llm',
        'tempo_ms',
    ];

    protected function casts(): array
    {
        return [
            'dados_extraidos' => 'array',
            'verificacoes' => 'array',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    /**
     * As observacoes sao gravadas uma por linha.
     *
     * @return string[]
     */
    public function observacoesComoLista(): array
    {
        if (blank($this->observacoes)) {
            return [];
        }

        return preg_split('/\r\n|\r|\n/', trim($this->observacoes)) ?: [];
    }

    public function tempoEmSegundos(): ?float
    {
        return $this->tempo_ms === null ? null : round($this->tempo_ms / 1000, 1);
    }
}
