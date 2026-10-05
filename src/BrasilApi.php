<?php
/**
 * Cliente da API publica BrasilAPI (recurso CNPJ).
 *
 * Quem chama a API publica e o SERVIDOR, nunca o navegador. Assim a aplicacao
 * controla o tratamento de erro, o timeout e o que sera gravado no banco.
 *
 * Endpoint: GET https://brasilapi.com.br/api/cnpj/v1/{cnpj}
 */

declare(strict_types=1);

final class BrasilApi
{
    private const BASE_URL = 'https://brasilapi.com.br/api/cnpj/v1/';
    private const TIMEOUT  = 15;

    /**
     * Consulta um CNPJ (somente digitos).
     *
     * @return array{status:int, dados:array<string,mixed>|null, falhaRede:?string}
     */
    public static function consultarCnpj(string $cnpj): array
    {
        $url = self::BASE_URL . rawurlencode($cnpj);

        $ch = curl_init($url);

        if ($ch === false) {
            return ['status' => 0, 'dados' => null, 'falhaRede' => 'Nao foi possivel iniciar o cURL.'];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                // Boa pratica pedida pelas APIs publicas: identificar o cliente.
                'User-Agent: Trabalho1-LP8-UNIFEG (consumir_API)',
            ],
        ]);

        $corpo     = curl_exec($ch);
        $status    = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $erroRede  = curl_errno($ch) !== 0 ? curl_error($ch) : null;

        curl_close($ch);

        if ($erroRede !== null) {
            return ['status' => 0, 'dados' => null, 'falhaRede' => $erroRede];
        }

        $dados = is_string($corpo) ? json_decode($corpo, true) : null;

        return [
            'status'    => $status,
            'dados'     => is_array($dados) ? $dados : null,
            'falhaRede' => null,
        ];
    }

    /**
     * Converte a resposta da BrasilAPI para as colunas da tabela `consultas`.
     * Guardamos so o essencial, nao o JSON inteiro.
     *
     * @param  array<string,mixed> $dados
     * @return array<string,string|null>
     */
    public static function mapearParaTabela(array $dados): array
    {
        $texto = static function (mixed $valor): ?string {
            if ($valor === null || $valor === '') {
                return null;
            }
            return trim((string) $valor);
        };

        $cnae = $texto($dados['cnae_fiscal_descricao'] ?? null);
        $codigoCnae = $texto($dados['cnae_fiscal'] ?? null);

        if ($cnae !== null && $codigoCnae !== null) {
            $cnae = $codigoCnae . ' - ' . $cnae;
        }

        return [
            'razao_social'       => $texto($dados['razao_social'] ?? null) ?? 'Nao informado',
            'nome_fantasia'      => $texto($dados['nome_fantasia'] ?? null),
            'situacao_cadastral' => $texto($dados['descricao_situacao_cadastral'] ?? null),
            'municipio'          => $texto($dados['municipio'] ?? null),
            'uf'                 => $texto($dados['uf'] ?? null),
            'cnae_principal'     => $cnae,
        ];
    }
}
