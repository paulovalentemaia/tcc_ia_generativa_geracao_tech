<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Empresa extends Model
{
    protected $table = 'empresas';

    protected $fillable = [
        'razao_social',
        'nome_fantasia',
        'cnpj',
        'data_inscricao_cnpj',
        'arquivo_comprovante',
        'situacao',
    ];

    protected function casts(): array
    {
        return [
            'data_inscricao_cnpj' => 'date',
        ];
    }

    public function avaliacoes(): HasMany
    {
        return $this->hasMany(Avaliacao::class);
    }

    /**
     * A avaliacao mais recente, usada na lista para mostrar o ultimo resultado.
     */
    public function ultimaAvaliacao(): HasOne
    {
        return $this->hasOne(Avaliacao::class)->latestOfMany();
    }

    /**
     * So empresas submetidas entram na avaliacao; as em 'criando' ficam de fora.
     */
    public function scopeSubmetidas(Builder $query): Builder
    {
        return $query->where('situacao', 'submetido');
    }

    /**
     * CNPJ no formato 00.000.000/0000-00, para as telas e as mensagens.
     */
    public function cnpjFormatado(): string
    {
        return preg_replace(
            '/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/',
            '$1.$2.$3/$4-$5',
            $this->cnpj
        ) ?? $this->cnpj;
    }
}
