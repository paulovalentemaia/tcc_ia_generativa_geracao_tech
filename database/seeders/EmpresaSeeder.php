<?php

namespace Database\Seeders;

use App\Models\Empresa;
use Illuminate\Database\Seeder;

/**
 * Inscricoes mockadas. Cada linha cobre um caso da tabela da spec 02.
 *
 * Os comprovantes sao documentos reais, baixados da Receita Federal. Onde o caso
 * exige divergencia, ela esta no CADASTRO abaixo, nunca no documento: o papel
 * continua integro e quem erra e a inscricao, que e exatamente o que o sistema
 * precisa detectar.
 */
class EmpresaSeeder extends Seeder
{
    public function run(): void
    {
        Empresa::query()->delete();

        foreach ($this->empresas() as $empresa) {
            Empresa::query()->create($empresa);
        }
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function empresas(): array
    {
        return [
            // 01 - tudo correto -> aprovado
            [
                'razao_social' => 'PETROLEO BRASILEIRO S A PETROBRAS',
                'nome_fantasia' => 'PETROBRAS - EDISE',
                'cnpj' => '33000167000101',
                'data_inscricao_cnpj' => '1966-09-28',
                'arquivo_comprovante' => 'comprovantes/empresa_01.pdf',
                'situacao' => 'submetido',
            ],

            // 02 - tudo correto, comprovante em imagem -> aprovado
            [
                'razao_social' => 'BANCO DO BRASIL SA',
                'nome_fantasia' => 'DIRECAO GERAL',
                'cnpj' => '00000000000191',
                'data_inscricao_cnpj' => '1966-08-01',
                'arquivo_comprovante' => 'comprovantes/empresa_02.jpeg',
                'situacao' => 'submetido',
            ],

            // 03 - filial aberta em 08/07/2025, menos de 2 anos ate 30/11/2026 -> reprovado
            [
                'razao_social' => 'RAIA DROGASIL S/A',
                'nome_fantasia' => null,
                'cnpj' => '61585865390006',
                'data_inscricao_cnpj' => '2025-07-08',
                'arquivo_comprovante' => 'comprovantes/empresa_03.pdf',
                'situacao' => 'submetido',
            ],

            // 04 - o comprovante e da matriz (0001-00); aqui foi cadastrada a
            //      filial 0002-90, entao o CNPJ nao bate -> reprovado
            [
                'razao_social' => 'AMBEV S.A.',
                'nome_fantasia' => null,
                'cnpj' => '07526557000290',
                'data_inscricao_cnpj' => '2005-07-19',
                'arquivo_comprovante' => 'comprovantes/empresa_04.pdf',
                'situacao' => 'submetido',
            ],

            // 05 - o comprovante diz 24/10/1966; aqui foi cadastrado 12/03/1980 -> reprovado
            [
                'razao_social' => 'MAGAZINE LUIZA S/A',
                'nome_fantasia' => 'MAGAZINE LUIZA',
                'cnpj' => '47960950000121',
                'data_inscricao_cnpj' => '1980-03-12',
                'arquivo_comprovante' => 'comprovantes/empresa_05.pdf',
                'situacao' => 'submetido',
            ],

            // 06 - situacao cadastral BAIXADA no comprovante -> reprovado
            [
                'razao_social' => 'LOJAS AMERICANAS S.A.',
                'nome_fantasia' => null,
                'cnpj' => '33014556000196',
                'data_inscricao_cnpj' => '1966-08-01',
                'arquivo_comprovante' => 'comprovantes/empresa_06.pdf',
                'situacao' => 'submetido',
            ],

            // 07 - cadastro correto, mas o arquivo enviado nao e um comprovante -> reprovado
            [
                'razao_social' => 'LOJAS RENNER S.A.',
                'nome_fantasia' => null,
                'cnpj' => '92754738000162',
                'data_inscricao_cnpj' => '1966-08-10',
                'arquivo_comprovante' => 'comprovantes/empresa_07.pdf',
                'situacao' => 'submetido',
            ],

            // 08 - ainda em 'criando' -> nao aparece na avaliacao
            [
                'razao_social' => 'VALE S.A.',
                'nome_fantasia' => null,
                'cnpj' => '33592510000154',
                'data_inscricao_cnpj' => '1969-02-11',
                'arquivo_comprovante' => 'comprovantes/empresa_08.pdf',
                'situacao' => 'criando',
            ],
        ];
    }
}
