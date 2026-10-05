<?php
/**
 * Padroniza toda saida do servidor em JSON, sempre com um status HTTP coerente.
 */

declare(strict_types=1);

final class Resposta
{
    /**
     * Envia o corpo em JSON e encerra o script.
     *
     * @param array<string,mixed> $corpo
     */
    public static function json(int $status, array $corpo): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');

        // 204 No Content nao pode ter corpo.
        if ($status !== 204) {
            echo json_encode(
                $corpo,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
            );
        }

        exit;
    }

    /**
     * Erro no formato { "erro": { "codigo": ..., "mensagem": ... } }.
     */
    public static function erro(int $status, string $mensagem, string $codigo = ''): void
    {
        self::json($status, [
            'erro' => [
                'codigo'   => $codigo !== '' ? $codigo : 'http_' . $status,
                'mensagem' => $mensagem,
            ],
        ]);
    }

    /**
     * Le o corpo da requisicao como JSON (aceita tambem form-urlencoded).
     *
     * @return array<string,mixed>
     */
    public static function corpoJson(): array
    {
        $bruto = file_get_contents('php://input');

        if ($bruto === false || trim($bruto) === '') {
            return [];
        }

        $tipo = $_SERVER['CONTENT_TYPE'] ?? '';

        if (stripos($tipo, 'application/x-www-form-urlencoded') !== false) {
            parse_str($bruto, $dados);
            return is_array($dados) ? $dados : [];
        }

        $dados = json_decode($bruto, true);

        if (!is_array($dados)) {
            self::erro(400, 'O corpo da requisicao precisa ser um JSON valido.', 'json_invalido');
        }

        return $dados;
    }
}
