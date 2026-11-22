<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Falha ao extrair os campos do comprovante com o LLM.
 *
 * Como as mensagens aparecem na tela de detalhe da avaliacao, elas dizem o que
 * aconteceu em portugues e, quando da, o que fazer.
 */
class ExtracaoException extends RuntimeException
{
    public static function ollamaInacessivel(string $url, string $motivo): self
    {
        return new self("Não foi possível falar com o Ollama em {$url}: {$motivo}");
    }

    public static function respostaComErro(int $status, string $corpo): self
    {
        $trecho = mb_strimwidth(trim($corpo), 0, 200, '...');

        return new self("O Ollama respondeu HTTP {$status}: {$trecho}");
    }

    public static function respostaVazia(): self
    {
        return new self('O Ollama respondeu sem conteúdo.');
    }

    public static function jsonInvalido(string $conteudo): self
    {
        $trecho = mb_strimwidth(trim($conteudo), 0, 200, '...');

        return new self("O modelo não devolveu JSON válido: {$trecho}");
    }

    public static function campoFaltando(string $campo): self
    {
        return new self("O modelo não devolveu o campo obrigatório '{$campo}'.");
    }
}
