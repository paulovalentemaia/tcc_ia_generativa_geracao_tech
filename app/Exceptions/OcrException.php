<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Falha ao extrair o texto de um comprovante.
 *
 * As mensagens sao escritas para aparecer na tela de detalhe da avaliacao,
 * entao devem dizer o que aconteceu em portugues, sem jargao de stack trace.
 */
class OcrException extends RuntimeException
{
    public static function arquivoNaoEncontrado(string $caminho): self
    {
        return new self("Comprovante não encontrado no disco: {$caminho}");
    }

    public static function processoFalhou(string $saidaDeErro): self
    {
        $detalhe = trim($saidaDeErro);

        return new self(
            'O processo de OCR falhou' . ($detalhe === '' ? '.' : ": {$detalhe}")
        );
    }

    public static function respostaInvalida(string $saida): self
    {
        $trecho = mb_strimwidth(trim($saida), 0, 200, '...');

        return new self("O OCR devolveu uma resposta que não é JSON válido: {$trecho}");
    }

    public static function recusado(string $erro): self
    {
        return new self("O OCR não conseguiu ler o arquivo: {$erro}");
    }

    public static function textoVazio(): self
    {
        return new self('O OCR não encontrou texto no comprovante.');
    }
}
