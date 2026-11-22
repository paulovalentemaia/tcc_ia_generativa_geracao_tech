<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Impede a avaliacao quando o sistema ainda nao esta pronto para avaliar.
 *
 * Diferente da OcrException e da ExtracaoException, que sao falhas no meio do
 * fluxo e viram uma avaliacao com resultado 'erro', esta barra antes de comecar:
 * nao faz sentido gastar OCR e LLM para depois descobrir que falta configuracao.
 */
class AvaliacaoException extends RuntimeException
{
    public static function semDataFimInscricoes(): self
    {
        return new self(
            'A data de fim das inscrições não está configurada. '
            . 'Preencha-a nas configurações antes de avaliar.'
        );
    }
}
