<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuracoes_avaliacao', function (Blueprint $table) {
            $table->bigIncrements('id');
            // Anulavel de proposito: a spec 05 exige bloquear a avaliacao
            // enquanto a data de fim das inscricoes nao estiver configurada.
            $table->date('data_fim_inscricoes')->nullable();
            $table->unsignedTinyInteger('anos_minimos')->default(2);
            $table->string('modelo_llm')->default('qwen3:4b');
            $table->string('ollama_url')->default('http://ollama:11434');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configuracoes_avaliacao');
    }
};
