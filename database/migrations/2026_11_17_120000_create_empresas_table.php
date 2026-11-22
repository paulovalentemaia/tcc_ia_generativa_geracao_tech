<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('empresas', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('razao_social', 255);
            $table->string('nome_fantasia', 255)->nullable();
            $table->char('cnpj', 14)->unique();
            $table->date('data_inscricao_cnpj');
            $table->string('arquivo_comprovante');
            $table->enum('situacao', ['criando', 'submetido'])->default('criando');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('empresas');
    }
};
