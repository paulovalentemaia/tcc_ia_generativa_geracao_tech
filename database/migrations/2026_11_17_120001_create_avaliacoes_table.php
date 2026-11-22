<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('avaliacoes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->enum('resultado', ['aprovado', 'reprovado', 'erro']);
            $table->text('observacoes')->nullable();
            $table->longText('texto_ocr')->nullable();
            $table->json('dados_extraidos')->nullable();
            $table->string('modelo_llm')->nullable();
            $table->unsignedInteger('tempo_ms')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avaliacoes');
    }
};
