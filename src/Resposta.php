<?php

declare(strict_types=1);

final class Resposta
{
    public static function json(int $status, array $corpo): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');

        if ($status !== 204) {
            echo json_encode(
                $corpo,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
            );
        }

        exit;
    }

    public static function erro(int $status, string $mensagem, string $codigo = ''): void
    {
        self::json($status, [
            'erro' => [
                'codigo'   => $codigo !== '' ? $codigo : 'http_' . $status,
                'mensagem' => $mensagem,
            ],
        ]);
    }

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
