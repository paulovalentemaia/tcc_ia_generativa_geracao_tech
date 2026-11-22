<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Interpretador Python
    |--------------------------------------------------------------------------
    |
    | Caminho do Python do venv onde o Indra OCR esta instalado. Dentro do
    | container 'app' e /opt/indra/bin/python.
    |
    */

    'python' => env('OCR_PYTHON', '/opt/indra/bin/python'),

    /*
    |--------------------------------------------------------------------------
    | Script de extracao
    |--------------------------------------------------------------------------
    |
    | Caminho do script, relativo a raiz do projeto. Recebe o caminho absoluto
    | do arquivo e devolve JSON no stdout.
    |
    */

    'script' => env('OCR_SCRIPT', 'scripts/ocr/ocr.py'),

];
