<?php

use App\Http\Controllers\AvaliacaoController;
use App\Http\Controllers\ConfiguracaoController;
use Illuminate\Support\Facades\Route;

Route::get('/', [AvaliacaoController::class, 'index'])->name('avaliacoes.index');
Route::get('/avaliacoes', [AvaliacaoController::class, 'index']);
Route::post('/avaliacoes', [AvaliacaoController::class, 'avaliarTodas'])->name('avaliacoes.todas');
Route::post('/avaliacoes/{empresa}', [AvaliacaoController::class, 'avaliar'])->name('avaliacoes.avaliar');
Route::get('/avaliacoes/detalhe/{avaliacao}', [AvaliacaoController::class, 'detalhe'])->name('avaliacoes.detalhe');
Route::get('/avaliacoes/comprovante/{empresa}', [AvaliacaoController::class, 'comprovante'])->name('avaliacoes.comprovante');

Route::get('/configuracoes', [ConfiguracaoController::class, 'edit'])->name('configuracoes.edit');
Route::put('/configuracoes', [ConfiguracaoController::class, 'update'])->name('configuracoes.update');
Route::post('/configuracoes/testar-conexao', [ConfiguracaoController::class, 'testarConexao'])->name('configuracoes.testar');
