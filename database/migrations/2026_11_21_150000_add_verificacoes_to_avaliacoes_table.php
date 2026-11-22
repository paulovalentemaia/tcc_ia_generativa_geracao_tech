<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A tela de detalhe (spec 07) mostra uma tabela com as seis regras, o valor
 * cadastrado, o valor do comprovante e o resultado de cada uma.
 *
 * Isso e gravado em vez de recalculado na hora de exibir porque a configuracao
 * muda: alterar a data de fim das inscricoes faria os certos e errados da tela
 * divergirem do resultado ja registrado. A avaliacao tem que continuar contando
 * o que valia quando foi feita.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('avaliacoes', function (Blueprint $table) {
            $table->json('verificacoes')->nullable()->after('dados_extraidos');
        });
    }

    public function down(): void
    {
        Schema::table('avaliacoes', function (Blueprint $table) {
            $table->dropColumn('verificacoes');
        });
    }
};
